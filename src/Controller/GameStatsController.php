<?php

namespace App\Controller;

use App\Repository\AchievementCacheRepository;
use App\Repository\GameCompletionRepository;
use App\Repository\HltbCacheRepository;
use App\Repository\UserProfileRepository;
use App\Service\BadgeService;
use App\Service\OgImageService;
use App\Service\SteamApiService;
use App\Service\UserTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stats dashboard and OG image generation for a player's library.
 */
class GameStatsController extends AbstractController
{
    public function __construct(
        private readonly SteamApiService $steamApiService,
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly HltbCacheRepository $hltbCacheRepository,
        private readonly AchievementCacheRepository $achievementCacheRepository,
        private readonly UserTokenService $userTokenService,
        private readonly BadgeService $badgeService,
        private readonly OgImageService $ogImageService,
    ) {}

    // ── Routes ────────────────────────────────────────────────────────────────

    #[Route('/games/{steamId}/stats', name: 'game_stats',
        requirements: ['steamId' => '\d{17}'], methods: ['GET'])]
    public function stats(string $steamId, Request $request): Response
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->redirectToRoute('game_library_home');
        }

        $token   = $this->userTokenService->getToken($request);
        $isOwner = $token !== null && $token === $profile->getUserToken();

        try { $games = $this->steamApiService->getOwnedGames($steamId); }
        catch (\RuntimeException) { $games = []; }

        try { $player = $this->steamApiService->getPlayerSummary($steamId); }
        catch (\RuntimeException) { $player = []; }

        $appIds         = array_column($games, 'appid');
        $completionMap  = $this->gameCompletionRepository->findAllIndexedByAppId($profile->getUserToken());
        $hltbMap        = $this->hltbCacheRepository->findByAppIds($appIds);
        $achievementMap = $this->achievementCacheRepository->findByAppIdsAndSteamId($appIds, $steamId);

        // ── Status counts & ratings ───────────────────────────────────────────
        $statusCounts = ['completed' => 0, 'playing' => 0, 'dropped' => 0, 'on_hold' => 0, 'unplayed' => 0];
        $ratings      = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $ratingSum    = 0;
        $ratedCount   = 0;

        foreach ($games as $game) {
            $completion = $completionMap[$game['appid']] ?? null;
            $status     = $completion?->getStatus()->value ?? 'unplayed';
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            if (($r = $completion?->getRating()) !== null) {
                $ratings[$r]++;
                $ratingSum += $r;
                $ratedCount++;
            }
        }

        // ── Top 10 most played ────────────────────────────────────────────────
        $sorted    = $games;
        usort($sorted, fn($a, $b) => $b['playtime_forever'] - $a['playtime_forever']);
        $topPlayed = array_slice(
            array_map(fn($g) => [
                'appid'  => $g['appid'],
                'name'   => $g['name'],
                'hours'  => round($g['playtime_forever'] / 60, 1),
                'status' => ($completionMap[$g['appid']] ?? null)?->getStatus()->value ?? 'unplayed',
            ], array_filter($sorted, fn($g) => $g['playtime_forever'] > 0)),
            0, 10
        );

        // ── Playtime insights ─────────────────────────────────────────────────
        $playtimeByStatus     = ['completed' => [], 'dropped' => []];
        $mostPlayedUnfinished = null;

        foreach ($games as $game) {
            $mins       = $game['playtime_forever'];
            $completion = $completionMap[$game['appid']] ?? null;
            $status     = $completion?->getStatus()->value ?? 'unplayed';

            if (isset($playtimeByStatus[$status])) {
                $playtimeByStatus[$status][] = $mins;
            }
            if ($status !== 'completed' && $mins > 0
                && ($mostPlayedUnfinished === null || $mins > $mostPlayedUnfinished['mins'])) {
                $mostPlayedUnfinished = [
                    'appid' => $game['appid'], 'name' => $game['name'],
                    'mins'  => $mins,          'status' => $status,
                ];
            }
        }

        $avgCompleted = !empty($playtimeByStatus['completed'])
            ? round(array_sum($playtimeByStatus['completed']) / count($playtimeByStatus['completed']) / 60, 1)
            : null;
        $avgDropped = !empty($playtimeByStatus['dropped'])
            ? round(array_sum($playtimeByStatus['dropped']) / count($playtimeByStatus['dropped']) / 60, 1)
            : null;

        if ($mostPlayedUnfinished !== null) {
            $mostPlayedUnfinished['hours'] = round($mostPlayedUnfinished['mins'] / 60, 1);
            unset($mostPlayedUnfinished['mins']);
        }

        // ── Favourites: spotlight games first, fallback to top-rated ──────────
        $favourites       = [];
        $favouritesManual = false;

        foreach ($games as $game) {
            $completion = $completionMap[$game['appid']] ?? null;
            if ($completion?->isSpotlight()) {
                $favourites[]     = [
                    'appid'    => $game['appid'],
                    'name'     => $game['name'],
                    'rating'   => $completion->getRating(),
                    'playtime' => $game['playtime_forever'],
                ];
                $favouritesManual = true;
            }
        }

        if (!$favouritesManual) {
            foreach ($games as $game) {
                $completion = $completionMap[$game['appid']] ?? null;
                if (($completion?->getRating() ?? 0) >= 4) {
                    $favourites[] = [
                        'appid'    => $game['appid'],
                        'name'     => $game['name'],
                        'rating'   => $completion->getRating(),
                        'playtime' => $game['playtime_forever'],
                    ];
                }
            }
            usort($favourites, fn($a, $b) =>
                $b['rating'] <=> $a['rating'] ?: $b['playtime'] <=> $a['playtime']
            );
        }

        $favourites = array_slice($favourites, 0, 8);

        // ── Backlog estimate ──────────────────────────────────────────────────
        $backlogGames    = 0;
        $backlogHours    = 0.0;
        $backlogWithHltb = 0;

        foreach ($games as $game) {
            $status = ($completionMap[$game['appid']] ?? null)?->getStatus()->value ?? 'unplayed';
            if ($status === 'unplayed') {
                $backlogGames++;
                $hltb = $hltbMap[$game['appid']] ?? null;
                if ($hltb?->getHoursMain() !== null) {
                    $backlogHours += $hltb->getHoursMain();
                    $backlogWithHltb++;
                }
            }
        }

        // ── Perfect games (100% achievements) ────────────────────────────────
        $perfectCount = 0;
        foreach ($achievementMap as $ach) {
            if ($ach->getTotalCount() > 0 && $ach->getUnlockedCount() === $ach->getTotalCount()) {
                $perfectCount++;
            }
        }

        $totalHours = round(array_sum(array_column($games, 'playtime_forever')) / 60, 1);

        $allBadges        = $this->badgeService->computeBadges(array_values($completionMap), (float) $totalHours, count($games));
        $earnedBadges     = $this->badgeService->earnedBadges($allBadges);

        return $this->render('games/stats.html.twig', [
            'steam_id'               => $steamId,
            'player'                 => $player,
            'is_owner'               => $isOwner,
            'total_games'            => count($games),
            'total_hours'            => $totalHours,
            'earned_badges'          => $earnedBadges,
            'earned_badge_count'     => count($earnedBadges),
            'total_badge_count'      => count($allBadges),
            'status_counts'          => $statusCounts,
            'ratings'                => $ratings,
            'max_rating_count'       => !empty($ratings) ? max($ratings) : 0,
            'avg_rating'             => $ratedCount > 0 ? round($ratingSum / $ratedCount, 2) : null,
            'rated_count'            => $ratedCount,
            'top_played'             => $topPlayed,
            'avg_playtime_completed' => $avgCompleted,
            'avg_playtime_dropped'   => $avgDropped,
            'most_played_unfinished' => $mostPlayedUnfinished,
            'favourites'             => $favourites,
            'favourites_manual'      => $favouritesManual,
            'backlog_games'          => $backlogGames,
            'backlog_hours'          => (int) round($backlogHours),
            'backlog_with_hltb'      => $backlogWithHltb,
            'perfect_count'          => $perfectCount,
        ]);
    }

    #[Route('/games/{steamId}/og-image', name: 'game_og_image',
        requirements: ['steamId' => '\d{17}'], methods: ['GET'])]
    public function ogImage(string $steamId): Response
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return new Response('Not found', 404);
        }

        try { $player = $this->steamApiService->getPlayerSummary($steamId); }
        catch (\RuntimeException) { $player = []; }

        try { $games = $this->steamApiService->getOwnedGames($steamId); }
        catch (\RuntimeException) { $games = []; }

        $completionMap = $this->gameCompletionRepository->findAllIndexedByAppId($profile->getUserToken());

        $png = $this->ogImageService->generate($player, $games, $completionMap, $steamId);

        return new Response($png, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}

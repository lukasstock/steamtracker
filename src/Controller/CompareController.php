<?php

namespace App\Controller;

use App\Repository\GameCompletionRepository;
use App\Repository\UserProfileRepository;
use App\Service\BadgeService;
use App\Service\SteamApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CompareController extends AbstractController
{
    public function __construct(
        private readonly SteamApiService $steamApiService,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly BadgeService $badgeService,
    ) {}

    #[Route('/games/{steamId}/compare', name: 'game_compare_pick', requirements: ['steamId' => '\d{17}'], methods: ['GET'])]
    public function friendPicker(string $steamId): Response
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->redirectToRoute('game_library_home');
        }

        try { $player = $this->steamApiService->getPlayerSummary($steamId); }
        catch (\RuntimeException) { $player = []; }

        $friendIds = $this->steamApiService->getFriendList($steamId);

        if ($friendIds === null) {
            return $this->render('games/compare_pick.html.twig', [
                'steam_id' => $steamId,
                'player'   => $player,
                'friends'  => null,
            ]);
        }

        // Batch-fetch summaries (Steam allows 100 per call)
        $summaries = [];
        foreach (array_chunk($friendIds, 100) as $chunk) {
            try {
                foreach ($this->steamApiService->getPlayersSummaries($chunk) as $p) {
                    $summaries[$p['steamid']] = $p;
                }
            } catch (\RuntimeException) {}
        }

        $profiles = $this->userProfileRepository->findBySteamIds($friendIds);

        $friends = [];
        foreach ($friendIds as $fid) {
            $s = $summaries[$fid] ?? null;
            if ($s === null) continue;
            $friends[] = [
                'steamid'     => $fid,
                'name'        => $s['personaname'] ?? 'Unknown',
                'avatar'      => $s['avatarmedium'] ?? '',
                'has_library' => isset($profiles[$fid]),
                'now_playing' => $s['gameextrainfo'] ?? null,
            ];
        }

        usort($friends, function ($a, $b) {
            if ($a['has_library'] !== $b['has_library']) {
                return $b['has_library'] <=> $a['has_library'];
            }
            return strcmp($a['name'], $b['name']);
        });

        return $this->render('games/compare_pick.html.twig', [
            'steam_id' => $steamId,
            'player'   => $player,
            'friends'  => $friends,
        ]);
    }

    #[Route('/games/{steamId}/compare/{friendSteamId}', name: 'game_compare', requirements: ['steamId' => '\d{17}', 'friendSteamId' => '\d{17}'], methods: ['GET'])]
    public function compare(string $steamId, string $friendSteamId): Response
    {
        $profileA = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profileA === null) {
            return $this->redirectToRoute('game_library_home');
        }

        try { $playerA = $this->steamApiService->getPlayerSummary($steamId); }
        catch (\RuntimeException) { $playerA = []; }

        try { $playerB = $this->steamApiService->getPlayerSummary($friendSteamId); }
        catch (\RuntimeException) { $playerB = []; }

        $profileB = $this->userProfileRepository->findOneBy(['steamId' => $friendSteamId]);

        if ($profileB === null) {
            return $this->render('games/compare.html.twig', [
                'steam_id'        => $steamId,
                'friend_steam_id' => $friendSteamId,
                'player_a'        => $playerA,
                'player_b'        => $playerB,
                'no_library'      => true,
                'shared'          => [],
                'counts'          => [],
            ]);
        }

        try { $gamesA = $this->steamApiService->getOwnedGames($steamId); }
        catch (\RuntimeException) { $gamesA = []; }

        try { $gamesB = $this->steamApiService->getOwnedGames($friendSteamId); }
        catch (\RuntimeException) { $gamesB = []; }

        $mapA = array_column($gamesA, null, 'appid');
        $mapB = array_column($gamesB, null, 'appid');

        $sharedIds = array_intersect(array_keys($mapA), array_keys($mapB));

        $completionsA = $this->gameCompletionRepository->findAllIndexedByAppId($profileA->getUserToken());
        $completionsB = $this->gameCompletionRepository->findAllIndexedByAppId($profileB->getUserToken());

        $shared = [];
        foreach ($sharedIds as $appId) {
            $compA   = $completionsA[$appId] ?? null;
            $compB   = $completionsB[$appId] ?? null;
            $statusA = $compA?->getStatus()->value ?? 'unplayed';
            $statusB = $compB?->getStatus()->value ?? 'unplayed';

            $category = match (true) {
                $statusA === 'completed' && $statusB === 'completed' => 'both_completed',
                $statusA === 'completed' && $statusB === 'unplayed'  => 'suggest_to_b',
                $statusB === 'completed' && $statusA === 'unplayed'  => 'suggest_to_a',
                $statusA === 'playing'   && $statusB === 'playing'   => 'both_playing',
                default                                              => 'other',
            };

            $shared[] = [
                'appid'           => $appId,
                'name'            => $mapA[$appId]['name'],
                'category'        => $category,
                'a_playtime_hrs'  => round($mapA[$appId]['playtime_forever'] / 60, 1),
                'a_playtime_mins' => $mapA[$appId]['playtime_forever'],
                'a_status'        => $statusA,
                'a_rating'        => $compA?->getRating(),
                'a_notes'         => ($compA !== null && $compA->isNotesPublic()) ? $compA->getNotes() : null,
                'b_playtime_hrs'  => round($mapB[$appId]['playtime_forever'] / 60, 1),
                'b_playtime_mins' => $mapB[$appId]['playtime_forever'],
                'b_status'        => $statusB,
                'b_rating'        => $compB?->getRating(),
                'b_notes'         => ($compB !== null && $compB->isNotesPublic()) ? $compB->getNotes() : null,
            ];
        }

        usort($shared, fn($a, $b) =>
            ($b['a_playtime_mins'] + $b['b_playtime_mins']) - ($a['a_playtime_mins'] + $a['b_playtime_mins'])
        );

        $counts = [
            'all'            => count($shared),
            'both_completed' => count(array_filter($shared, fn($g) => $g['category'] === 'both_completed')),
            'suggest_to_b'   => count(array_filter($shared, fn($g) => $g['category'] === 'suggest_to_b')),
            'suggest_to_a'   => count(array_filter($shared, fn($g) => $g['category'] === 'suggest_to_a')),
            'both_playing'   => count(array_filter($shared, fn($g) => $g['category'] === 'both_playing')),
            'only_a'         => count(array_diff(array_keys($mapA), array_keys($mapB))),
            'only_b'         => count(array_diff(array_keys($mapB), array_keys($mapA))),
        ];

        // Badges for both players
        $playtimeA  = round(array_sum(array_column($gamesA, 'playtime_forever')) / 60, 1);
        $playtimeB  = round(array_sum(array_column($gamesB, 'playtime_forever')) / 60, 1);
        $badgesA    = $this->badgeService->computeBadges(array_values($completionsA), $playtimeA, count($gamesA));
        $badgesB    = $this->badgeService->computeBadges(array_values($completionsB), $playtimeB, count($gamesB));
        $earnedA    = $this->badgeService->earnedBadges($badgesA);
        $earnedB    = $this->badgeService->earnedBadges($badgesB);

        // IDs earned by both
        $idsA       = array_column($earnedA, 'id');
        $idsB       = array_column($earnedB, 'id');
        $sharedBadgeIds = array_intersect($idsA, $idsB);

        return $this->render('games/compare.html.twig', [
            'steam_id'          => $steamId,
            'friend_steam_id'   => $friendSteamId,
            'player_a'          => $playerA,
            'player_b'          => $playerB,
            'shared'            => $shared,
            'counts'            => $counts,
            'no_library'        => false,
            'earned_a'          => $earnedA,
            'earned_b'          => $earnedB,
            'shared_badge_ids'  => array_values($sharedBadgeIds),
            'all_badges'        => $badgesA,
            'playtime_a'        => $playtimeA,
            'playtime_b'        => $playtimeB,
            'playtime_max'      => max($playtimeA, $playtimeB),
        ]);
    }
}

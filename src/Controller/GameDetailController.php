<?php

namespace App\Controller;

use App\Entity\AchievementCache;
use App\Entity\HltbCache;
use App\Repository\AchievementCacheRepository;
use App\Repository\ActivityLogRepository;
use App\Repository\GameCompletionRepository;
use App\Repository\HltbCacheRepository;
use App\Repository\UserProfileRepository;
use App\Service\HltbService;
use App\Service\SteamApiService;
use App\Service\UserTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles per-game pages: detail view, HLTB lookup, achievement sync, and
 * the header-image URL helper used by the detail modal.
 */
class GameDetailController extends AbstractController
{
    public function __construct(
        private readonly SteamApiService $steamApiService,
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly HltbCacheRepository $hltbCacheRepository,
        private readonly AchievementCacheRepository $achievementCacheRepository,
        private readonly ActivityLogRepository $activityLogRepository,
        private readonly HltbService $hltbService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserTokenService $userTokenService,
    ) {}

    // ── Routes ────────────────────────────────────────────────────────────────

    #[Route('/games/{steamId}/{appId}', name: 'game_detail',
        requirements: ['steamId' => '\d{17}', 'appId' => '\d+'], methods: ['GET'])]
    public function detail(string $steamId, int $appId, Request $request): Response
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->redirectToRoute('game_library_home');
        }

        $userToken = $profile->getUserToken();
        $token     = $this->userTokenService->getToken($request);
        $isOwner   = $token !== null && $token === $userToken;

        $visitorProfile = $token !== null && !$isOwner
            ? $this->userProfileRepository->findOneBy(['userToken' => $token])
            : null;
        $visitorSteamId = $visitorProfile?->getSteamId() ?? $steamId;

        try { $player = $this->steamApiService->getPlayerSummary($steamId); }
        catch (\RuntimeException) { $player = []; }

        try { $ownedGames = $this->steamApiService->getOwnedGames($steamId); }
        catch (\RuntimeException) { $ownedGames = []; }

        // Find this specific game in the owned list
        $gameData = null;
        foreach ($ownedGames as $g) {
            if ((int) $g['appid'] === $appId) { $gameData = $g; break; }
        }
        if ($gameData === null) {
            return $this->redirectToRoute('game_library', ['steamId' => $steamId]);
        }

        $completion  = $this->gameCompletionRepository->findOneByAppIdAndToken($appId, $userToken);
        $hltb        = $this->hltbCacheRepository->findOneBy(['appId' => $appId]);
        $achCache    = $this->achievementCacheRepository->findOneByAppIdAndSteamId($appId, $steamId);
        $activityLog = $this->activityLogRepository->findByUserAndApp($userToken, $appId);

        [$achievements, $hasAchievements] = $this->loadAchievements($steamId, $appId, $achCache);

        return $this->render('games/detail.html.twig', [
            'steam_id'         => $steamId,
            'app_id'           => $appId,
            'player'           => $player,
            'is_owner'         => $isOwner,
            'game'             => [
                'name'          => $gameData['name'],
                'playtime_hrs'  => round($gameData['playtime_forever'] / 60, 1),
                'playtime_mins' => $gameData['playtime_forever'],
            ],
            'completion'       => $completion,
            'hltb'             => $hltb,
            'ach_cache'        => $achCache,
            'achievements'     => $achievements,
            'has_achievements' => $hasAchievements,
            'activity_log'     => $activityLog,
            'visitor_steam_id' => $visitorSteamId,
            'friends_who_own'  => $this->loadFriendsWhoOwnGame($visitorSteamId, $appId),
        ]);
    }

    #[Route('/games/{steamId}/{appId}/image-url', name: 'game_image_url',
        requirements: ['steamId' => '\d{17}', 'appId' => '\d+'], methods: ['GET'])]
    public function imageUrl(string $steamId, int $appId): JsonResponse
    {
        return $this->json(['url' => $this->steamApiService->getHeaderImageUrl($appId)]);
    }

    #[Route('/games/{steamId}/{appId}/hltb', name: 'game_hltb',
        requirements: ['steamId' => '\d{17}', 'appId' => '\d+'], methods: ['GET'])]
    public function hltb(string $steamId, int $appId, Request $request): JsonResponse
    {
        $cached = $this->hltbCacheRepository->find($appId);

        if ($cached !== null) {
            $age = (new \DateTimeImmutable())->getTimestamp() - $cached->getFetchedAt()->getTimestamp();
            if ($age < 30 * 86400) {
                return $this->json(['hours' => $cached->getHoursMain(), 'fetched' => true]);
            }
        }

        $gameName = trim($request->query->getString('name'));
        if ($gameName === '') {
            return $this->json(['hours' => null, 'fetched' => false]);
        }

        $hours  = $this->hltbService->lookup($gameName);
        $cached ??= (new HltbCache())->setAppId($appId);
        $cached->setHoursMain($hours)->setFetchedAt(new \DateTimeImmutable());
        $this->entityManager->persist($cached);
        $this->entityManager->flush();

        return $this->json(['hours' => $hours, 'fetched' => true]);
    }

    #[Route('/games/{steamId}/{appId}/achievements', name: 'game_achievements',
        requirements: ['steamId' => '\d{17}', 'appId' => '\d+'], methods: ['GET'])]
    public function achievements(string $steamId, int $appId): JsonResponse
    {
        $cached = $this->achievementCacheRepository->findOneByAppIdAndSteamId($appId, $steamId);

        if ($cached !== null) {
            $age = (new \DateTimeImmutable())->getTimestamp() - $cached->getFetchedAt()->getTimestamp();
            if ($age < 86400) {
                return $this->json($this->buildAchievementPayload($cached));
            }
        }

        $achievements = $this->steamApiService->getPlayerAchievements($steamId, $appId);

        $cached ??= (new AchievementCache())->setSteamId($steamId)->setAppId($appId);

        if ($achievements === null) {
            // API error — don't cache, let the client retry
            return $this->json(['has_achievements' => null]);
        }

        if (count($achievements) === 0) {
            $cached->setTotalCount(0)->setUnlockedCount(0)->setRareAchievements(null)
                   ->setFetchedAt(new \DateTimeImmutable());
            $this->entityManager->persist($cached);
            $this->entityManager->flush();
            return $this->json(['has_achievements' => false]);
        }

        $unlocked      = array_filter($achievements, fn($a) => ($a['achieved'] ?? 0) === 1);
        $unlockedCount = count($unlocked);
        $totalCount    = count($achievements);
        $globalPcts    = $this->steamApiService->getGlobalAchievementPercentages($appId);

        // Build "rare" list from unlocked achievements sorted by rarity ascending
        $rareRaw = [];
        foreach ($unlocked as $ach) {
            $pct = $globalPcts[$ach['apiname']] ?? null;
            if ($pct !== null && $pct > 0) {
                $rareRaw[] = [
                    'apiname'       => $ach['apiname'],
                    'displayName'   => $ach['name'] ?? $ach['apiname'],
                    'description'   => $ach['description'] ?? '',
                    'globalPercent' => round($pct, 1),
                ];
            }
        }

        $rareForStorage = null;
        if (!empty($rareRaw)) {
            usort($rareRaw, fn($a, $b) => $a['globalPercent'] <=> $b['globalPercent']);
            $rareRaw = array_slice($rareRaw, 0, 8);
            $schema  = $this->steamApiService->getGameSchema($appId);
            $rareForStorage = array_map(fn($r) => [
                'displayName'   => $r['displayName'],
                'description'   => $r['description'] ?: ($schema[$r['apiname']]['description'] ?? ''),
                'icon'          => $schema[$r['apiname']]['icon'] ?? null,
                'globalPercent' => $r['globalPercent'],
            ], $rareRaw);
        }

        $cached->setUnlockedCount($unlockedCount)
               ->setTotalCount($totalCount)
               ->setRareAchievements($rareForStorage)
               ->setFetchedAt(new \DateTimeImmutable());
        $this->entityManager->persist($cached);
        $this->entityManager->flush();

        return $this->json($this->buildAchievementPayload($cached));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Loads the full achievement list from the Steam API for the detail view.
     * Returns [achievements[], hasAchievements: bool|null]
     *   null  = not yet synced
     *   false = game has no achievements
     *   true  = data is in the returned array
     */
    private function loadAchievements(string $steamId, int $appId, ?AchievementCache $achCache): array
    {
        if ($achCache === null) {
            return [[], null];
        }
        if ($achCache->getTotalCount() === 0) {
            return [[], false];
        }

        $achievements = [];
        try {
            $rawAchs    = $this->steamApiService->getPlayerAchievements($steamId, $appId);
            $schema     = $this->steamApiService->getGameSchema($appId);
            $globalPcts = $this->steamApiService->getGlobalAchievementPercentages($appId);

            if ($rawAchs !== null) {
                foreach ($rawAchs as $ach) {
                    $api            = $ach['apiname'];
                    $sc             = $schema[$api] ?? [];
                    $achievements[] = [
                        'apiname'       => $api,
                        'name'          => $ach['name'] ?? $sc['displayName'] ?? $api,
                        'description'   => $ach['description'] ?? $sc['description'] ?? '',
                        'achieved'      => (bool) ($ach['achieved'] ?? 0),
                        'unlocktime'    => $ach['unlocktime'] ?? 0,
                        'icon'          => $sc['icon'] ?? null,
                        'icongray'      => $sc['icongray'] ?? null,
                        'globalPercent' => $globalPcts[$api] ?? null,
                    ];
                }
                // Unlocked first (newest first), then locked (rarest first)
                usort($achievements, function ($a, $b) {
                    if ($a['achieved'] !== $b['achieved']) {
                        return (int) $b['achieved'] <=> (int) $a['achieved'];
                    }
                    return $a['achieved']
                        ? $b['unlocktime'] <=> $a['unlocktime']
                        : ($a['globalPercent'] ?? PHP_INT_MAX) <=> ($b['globalPercent'] ?? PHP_INT_MAX);
                });
            }
        } catch (\RuntimeException) {}

        return [$achievements, true];
    }

    /**
     * Returns friends (who have a tracker profile) that also own the given game.
     * Caps at 20 API calls to avoid timeouts.
     */
    private function loadFriendsWhoOwnGame(string $steamId, int $appId): array
    {
        $result = [];
        try {
            $friendIds = $this->steamApiService->getFriendList($steamId);
            if ($friendIds === null || empty($friendIds)) {
                return [];
            }

            $friendProfileMap = $this->userProfileRepository->findBySteamIds($friendIds);
            if (empty($friendProfileMap)) {
                return [];
            }

            $limitedMap = array_slice($friendProfileMap, 0, 20, true);

            try {
                $summaries = $this->steamApiService->getPlayersSummaries(array_keys($limitedMap));
            } catch (\RuntimeException) {
                $summaries = [];
            }
            $summaryMap = array_column($summaries, null, 'steamid');

            $tokens             = array_map(fn($p) => $p->getUserToken(), $limitedMap);
            $completionsByToken = $this->gameCompletionRepository->findByAppIdAndTokens($appId, $tokens);

            foreach ($limitedMap as $fSteamId => $fProfile) {
                try {
                    $fOwned = $this->steamApiService->getOwnedGames($fSteamId);
                } catch (\RuntimeException) {
                    continue;
                }
                $ownsGame = false;
                foreach ($fOwned as $fg) {
                    if ((int) $fg['appid'] === $appId) { $ownsGame = true; break; }
                }
                if (!$ownsGame) continue;

                $result[] = [
                    'steamId'    => $fSteamId,
                    'summary'    => $summaryMap[$fSteamId] ?? [],
                    'completion' => $completionsByToken[$fProfile->getUserToken()] ?? null,
                ];
            }
        } catch (\RuntimeException) {}

        return $result;
    }

    private function buildAchievementPayload(AchievementCache $cache): array
    {
        if ($cache->getTotalCount() === 0) {
            return ['has_achievements' => false];
        }

        $total    = $cache->getTotalCount();
        $unlocked = $cache->getUnlockedCount();

        return [
            'has_achievements' => true,
            'unlocked'         => $unlocked,
            'total'            => $total,
            'percent'          => round(($unlocked / $total) * 100, 1),
            'perfect'          => $unlocked === $total,
            'rare'             => $cache->getRareAchievements() ?? [],
        ];
    }
}

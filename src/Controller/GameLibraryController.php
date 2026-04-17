<?php

namespace App\Controller;

use App\Entity\GameCompletion;
use App\Enum\GameStatus;
use App\Repository\AchievementCacheRepository;
use App\Repository\GameCompletionRepository;
use App\Repository\HltbCacheRepository;
use App\Repository\UserProfileRepository;
use App\Service\ActivityLogService;
use App\Service\BadgeService;
use App\Service\SteamApiService;
use App\Service\UserTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Core library controller: landing page, the main game list, and the
 * per-game status/rating update endpoint.
 *
 * More specific concerns live in GameDetailController and GameStatsController.
 */
class GameLibraryController extends AbstractController
{
    public function __construct(
        private readonly SteamApiService $steamApiService,
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly HltbCacheRepository $hltbCacheRepository,
        private readonly AchievementCacheRepository $achievementCacheRepository,
        private readonly ActivityLogService $activityLogService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserTokenService $userTokenService,
        private readonly BadgeService $badgeService,
        private readonly string $friendInviteToken = '',
    ) {}

    // ── Landing & entry points ────────────────────────────────────────────────

    #[Route('/faq', name: 'faq', methods: ['GET'])]
    public function faq(Request $request): Response
    {
        $token   = $this->userTokenService->getToken($request);
        $steamId = null;
        if ($token !== null) {
            $profile = $this->userProfileRepository->findOneBy(['userToken' => $token]);
            $steamId = $profile?->getSteamId();
        }

        return $this->render('games/landing.html.twig', [
            'error'               => null,
            'friend_invite_token' => $this->friendInviteToken,
            'scroll_to_faq'       => true,
            'logged_in_steam_id'  => $steamId,
        ]);
    }

    #[Route('/', name: 'game_library_root', methods: ['GET'])]
    #[Route('/games', name: 'game_library_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $token = $this->userTokenService->getToken($request);
        if ($token !== null) {
            $profile = $this->userProfileRepository->findOneBy(['userToken' => $token]);
            if ($profile !== null) {
                return $this->redirectToRoute('game_library', ['steamId' => $profile->getSteamId()]);
            }
        }

        return $this->render('games/landing.html.twig', [
            'error'               => null,
            'friend_invite_token' => $this->friendInviteToken,
        ]);
    }

    #[Route('/games/view', name: 'game_library_view', methods: ['POST'])]
    public function view(Request $request): Response
    {
        $steamInput = trim($request->request->getString('steam_url'));
        if ($steamInput === '') {
            return $this->render('games/landing.html.twig', [
                'error'               => null,
                'friend_invite_token' => $this->friendInviteToken,
            ]);
        }

        try {
            $steamId = $this->steamApiService->resolveSteamInput($steamInput);
        } catch (\RuntimeException) {
            return $this->renderLanding('Could not connect to Steam. Please try again.');
        }

        if ($steamId === null) {
            return $this->renderLanding('Could not find that Steam profile. Make sure it\'s set to public.');
        }

        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->renderLanding('This user hasn\'t set up their tracker yet.');
        }

        return $this->redirectToRoute('game_library', ['steamId' => $steamId]);
    }

    // ── Main library page ─────────────────────────────────────────────────────

    #[Route('/games/{steamId}', name: 'game_library',
        requirements: ['steamId' => '\d{17}'], methods: ['GET'])]
    public function index(string $steamId, Request $request): Response
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->redirectToRoute('game_library_home');
        }

        $token         = $this->userTokenService->getToken($request);
        $isOwner       = $token !== null && $token === $profile->getUserToken();
        $imported      = $request->query->getInt('imported', 0);
        $autoCompleted = $request->query->getInt('auto_completed', 0);

        try { $games = $this->steamApiService->getOwnedGames($steamId); }
        catch (\RuntimeException) { $games = []; }

        usort($games, fn($a, $b) => strcmp($a['name'], $b['name']));

        $appIds         = array_column($games, 'appid');
        $completionMap  = $this->gameCompletionRepository->findAllIndexedByAppId($profile->getUserToken());
        $hltbMap        = $this->hltbCacheRepository->findByAppIds($appIds);
        $achievementMap = $this->achievementCacheRepository->findByAppIdsAndSteamId($appIds, $steamId);

        $gamesWithStatus = array_map(function (array $game) use ($completionMap, $hltbMap, $achievementMap) {
            $completion = $completionMap[$game['appid']] ?? null;
            $hltbEntry  = $hltbMap[$game['appid']] ?? null;
            $achEntry   = $achievementMap[$game['appid']] ?? null;
            $achPerfect = $achEntry !== null && $achEntry->getTotalCount() > 0
                          && $achEntry->getUnlockedCount() === $achEntry->getTotalCount();
            return [
                'appid'         => $game['appid'],
                'name'          => $game['name'],
                'playtime_hrs'  => round($game['playtime_forever'] / 60, 1),
                'playtime_mins' => $game['playtime_forever'],
                'recent_mins'   => $game['playtime_2weeks'] ?? 0,
                'status'        => $completion?->getStatus()->value ?? GameStatus::Unplayed->value,
                'rating'        => $completion?->getRating(),
                'notes'         => $completion?->getNotes() ?? '',
                'is_spotlight'  => $completion?->isSpotlight() ?? false,
                'notes_public'  => $completion?->isNotesPublic() ?? false,
                'completed_at'  => $completion?->getCompletedAt()?->format('M j, Y'),
                'hltb_fetched'  => $hltbEntry !== null,
                'hltb_hours'    => $hltbEntry?->getHoursMain(),
                'ach_fetched'   => $achEntry !== null,
                'ach_unlocked'  => $achEntry?->getUnlockedCount() ?? 0,
                'ach_total'     => $achEntry?->getTotalCount() ?? 0,
                'ach_perfect'   => $achPerfect,
            ];
        }, $games);

        $counts       = array_count_values(array_column($gamesWithStatus, 'status'));
        $totalHours   = (int) round(array_sum(array_column($games, 'playtime_forever')) / 60);
        $perfectCount = count(array_filter($gamesWithStatus, fn($g) => $g['ach_perfect']));

        $allBadges        = $this->badgeService->computeBadges(array_values($completionMap), (float) $totalHours, count($games));
        $topBadges        = $this->badgeService->topEarned($allBadges, 7);
        $earnedBadgeCount = count(array_filter($allBadges, fn($b) => $b['earned']));

        try { $nowPlaying = $this->steamApiService->getCurrentlyPlaying($steamId); }
        catch (\RuntimeException) { $nowPlaying = null; }

        try { $player = $this->steamApiService->getPlayerSummary($steamId); }
        catch (\RuntimeException) { $player = []; }

        $response = $this->render('games/index.html.twig', [
            'games'              => $gamesWithStatus,
            'steam_id'           => $steamId,
            'player'             => $player,
            'total'              => count($gamesWithStatus),
            'total_hours'        => $totalHours,
            'now_playing'        => $nowPlaying,
            'is_owner'           => $isOwner,
            'perfect_count'      => $perfectCount,
            'imported'           => $imported,
            'auto_completed'     => $autoCompleted,
            'top_badges'         => $topBadges,
            'earned_badge_count' => $earnedBadgeCount,
            'total_badge_count'  => count($allBadges),
            'counts'             => [
                'completed' => $counts[GameStatus::Completed->value] ?? 0,
                'playing'   => $counts[GameStatus::Playing->value]   ?? 0,
                'dropped'   => $counts[GameStatus::Dropped->value]   ?? 0,
                'on_hold'   => $counts[GameStatus::OnHold->value]    ?? 0,
                'unplayed'  => $counts[GameStatus::Unplayed->value]  ?? count($gamesWithStatus),
            ],
        ]);

        // Refresh the cookie on every owner visit so active users never expire
        if ($isOwner) {
            $this->userTokenService->setTokenCookie($response, $token);
        }

        return $response;
    }

    // ── Status / rating update (AJAX) ─────────────────────────────────────────

    #[Route('/games/{steamId}/{appId}/update', name: 'game_update',
        requirements: ['steamId' => '\d{17}', 'appId' => '\d+'], methods: ['POST'])]
    public function update(string $steamId, int $appId, Request $request): JsonResponse
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $token = $this->userTokenService->getToken($request);
        if ($token === null || $token !== $profile->getUserToken()) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $completion = $this->gameCompletionRepository->findOneByAppIdAndToken($appId, $profile->getUserToken());
        if ($completion === null) {
            $completion = (new GameCompletion())
                ->setAppId($appId)
                ->setUserToken($profile->getUserToken());
        }

        $previousStatus = $completion->getId() !== null ? $completion->getStatus() : null;
        $previousRating = $completion->getRating();

        $status = GameStatus::tryFrom($data['status'] ?? '') ?? GameStatus::Unplayed;
        $completion->setStatus($status);

        // Validate rating is within 1–5 range
        $ratingRaw = isset($data['rating']) ? (int) $data['rating'] : 0;
        $rating    = ($ratingRaw >= 1 && $ratingRaw <= 5) ? $ratingRaw : null;
        $completion->setRating($rating);

        // Limit notes length
        $notes = mb_substr(trim($data['notes'] ?? ''), 0, 2000);
        $completion->setNotes($notes !== '' ? $notes : null);

        $completion->setIsSpotlight((bool) ($data['spotlight'] ?? false));
        if (array_key_exists('notes_public', $data)) {
            $completion->setIsNotesPublic((bool) $data['notes_public']);
        }

        if ($status === GameStatus::Completed && $completion->getCompletedAt() === null) {
            $completion->setCompletedAt(new \DateTimeImmutable());
        } elseif ($status !== GameStatus::Completed) {
            $completion->setCompletedAt(null);
        }

        $this->entityManager->persist($completion);
        $this->entityManager->flush();

        // Delegate activity logging (with 1-hour deduplication)
        $appName = mb_substr(trim($data['app_name'] ?? ''), 0, 200);
        $this->activityLogService->logUpdate(
            $profile->getUserToken(),
            $steamId,
            $appId,
            $appName,
            $status,
            $previousStatus,
            $rating,
            $previousRating,
            $notes,
        );

        return $this->json([
            'status'      => $completion->getStatus()->value,
            'rating'      => $completion->getRating(),
            'notes'       => $completion->getNotes() ?? '',
            'spotlight'   => $completion->isSpotlight(),
            'notesPublic' => $completion->isNotesPublic(),
            'completedAt' => $completion->getCompletedAt()?->format('M j, Y'),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function renderLanding(string $error): Response
    {
        return $this->render('games/landing.html.twig', [
            'error'               => $error,
            'friend_invite_token' => $this->friendInviteToken,
        ]);
    }
}

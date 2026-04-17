<?php

namespace App\Controller;

use App\Repository\ActivityLogRepository;
use App\Repository\UserProfileRepository;
use App\Service\SteamApiService;
use App\Service\UserTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FeedController extends AbstractController
{
    public function __construct(
        private readonly ActivityLogRepository $activityLogRepository,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly SteamApiService $steamApiService,
        private readonly UserTokenService $userTokenService,
    ) {}

    #[Route('/feed', name: 'activity_feed', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $token = $this->userTokenService->getToken($request);

        // Feed requires a logged-in user — we need their Steam ID to fetch friends
        if ($token === null) {
            return $this->render('feed/index.html.twig', [
                'entries'       => [],
                'players'       => [],
                'logged_in'     => false,
                'friends_private' => false,
            ]);
        }

        $profile = $this->userProfileRepository->findOneBy(['userToken' => $token]);
        if ($profile === null) {
            return $this->render('feed/index.html.twig', [
                'entries'         => [],
                'players'         => [],
                'logged_in'       => false,
                'friends_private' => false,
            ]);
        }

        $mySteamId = $profile->getSteamId();

        // Fetch friend list — null means private
        $friendsPrivate = false;
        $allowedSteamIds = [$mySteamId];
        try {
            $friends = $this->steamApiService->getFriendList($mySteamId);
            if ($friends === null) {
                $friendsPrivate = true;
            } else {
                $allowedSteamIds = array_merge($allowedSteamIds, $friends);
            }
        } catch (\RuntimeException) {
            // Steam unavailable — fall back to own activity only
        }

        $entries = $this->activityLogRepository->findRecentForSteamIds($allowedSteamIds, 60);

        // Batch-load player summaries for all unique Steam IDs in the feed
        $steamIds = array_unique(array_map(fn($e) => $e->getSteamId(), $entries));
        $players  = [];
        if (!empty($steamIds)) {
            try {
                foreach (array_chunk($steamIds, 100) as $chunk) {
                    $batch = $this->steamApiService->getPlayersSummaries($chunk);
                    foreach ($batch as $p) {
                        $players[$p['steamid']] = $p;
                    }
                }
            } catch (\RuntimeException) {}
        }

        // Count entries per person for the leaderboard/filter sidebar
        $activityCounts = [];
        $completionCounts = [];
        foreach ($entries as $entry) {
            $sid = $entry->getSteamId();
            $activityCounts[$sid] = ($activityCounts[$sid] ?? 0) + 1;
            if ($entry->getType() === 'completed') {
                $completionCounts[$sid] = ($completionCounts[$sid] ?? 0) + 1;
            }
        }
        arsort($completionCounts);

        return $this->render('feed/index.html.twig', [
            'entries'           => $entries,
            'players'           => $players,
            'logged_in'         => true,
            'friends_private'   => $friendsPrivate,
            'my_steam_id'       => $mySteamId,
            'activity_counts'   => $activityCounts,
            'completion_counts' => $completionCounts,
        ]);
    }
}

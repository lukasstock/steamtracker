<?php

namespace App\Controller;

use App\Repository\GameCompletionRepository;
use App\Repository\UserProfileRepository;
use App\Service\BadgeService;
use App\Service\SteamApiService;
use App\Service\UserTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BadgesController extends AbstractController
{
    public function __construct(
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly SteamApiService $steamApiService,
        private readonly UserTokenService $userTokenService,
        private readonly BadgeService $badgeService,
    ) {}

    #[Route('/games/{steamId}/badges', name: 'game_badges', requirements: ['steamId' => '\d{17}'], methods: ['GET'])]
    public function index(string $steamId, Request $request): Response
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->redirectToRoute('game_library_home');
        }

        $userToken = $profile->getUserToken();
        $token     = $this->userTokenService->getToken($request);
        $isOwner   = $token !== null && $token === $userToken;

        try {
            $player = $this->steamApiService->getPlayerSummary($steamId);
        } catch (\RuntimeException) {
            $player = [];
        }

        $completionMap      = $this->gameCompletionRepository->findAllIndexedByAppId($userToken);
        $allCompletions     = array_values($completionMap);
        $totalPlaytimeHours = 0.0;
        $gameCount          = 0;

        try {
            $ownedGames         = $this->steamApiService->getOwnedGames($steamId);
            $totalPlaytimeHours = round(array_sum(array_column($ownedGames, 'playtime_forever')) / 60, 1);
            $gameCount          = count($ownedGames);
        } catch (\RuntimeException) {}

        $badges      = $this->badgeService->computeBadges($allCompletions, $totalPlaytimeHours, $gameCount);
        $earnedCount = count(array_filter($badges, fn($b) => $b['earned']));

        return $this->render('games/badges.html.twig', [
            'steam_id'     => $steamId,
            'player'       => $player,
            'is_owner'     => $isOwner,
            'badges'       => $badges,
            'earned_count' => $earnedCount,
            'total_count'  => count($badges),
        ]);
    }
}

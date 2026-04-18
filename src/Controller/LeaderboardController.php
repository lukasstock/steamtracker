<?php

namespace App\Controller;

use App\Repository\GameCompletionRepository;
use App\Repository\UserProfileRepository;
use App\Service\SteamApiService;
use App\Service\UserTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeaderboardController extends AbstractController
{
    public function __construct(
        private readonly UserProfileRepository $userProfileRepository,
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly SteamApiService $steamApiService,
        private readonly UserTokenService $userTokenService,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    #[Route('/leaderboard', name: 'leaderboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $token     = $this->userTokenService->getToken($request);
        $myProfile = $token ? $this->userProfileRepository->findOneBy(['userToken' => $token]) : null;

        $profiles = $this->userProfileRepository->findBy(['showInLeaderboard' => true]);
        $entries  = [];

        if (!empty($profiles)) {
            $tokens   = array_map(fn($p) => $p->getUserToken(), $profiles);
            $steamIds = array_map(fn($p) => $p->getSteamId(), $profiles);

            $players = [];
            try {
                foreach (array_chunk($steamIds, 100) as $chunk) {
                    $batch = $this->steamApiService->getPlayersSummaries($chunk);
                    foreach ($batch as $p) {
                        $players[$p['steamid']] = $p;
                    }
                }
            } catch (\RuntimeException) {}

            $completedCounts = $this->gameCompletionRepository->getCompletedCountsByTokens($tokens);

            foreach ($profiles as $profile) {
                $steamId   = $profile->getSteamId();
                $userToken = $profile->getUserToken();

                $totalOwned    = 0;
                $playtimeHours = 0.0;
                try {
                    $ownedGames    = $this->steamApiService->getOwnedGames($steamId);
                    $totalOwned    = count($ownedGames);
                    $totalMinutes  = array_sum(array_column($ownedGames, 'playtime_forever'));
                    $playtimeHours = round($totalMinutes / 60, 1);
                } catch (\RuntimeException) {}

                $completed      = $completedCounts[$userToken] ?? 0;
                $completionRate = $totalOwned > 0 ? round($completed / $totalOwned * 100, 1) : 0.0;

                $entries[] = [
                    'profile'         => $profile,
                    'player'          => $players[$steamId] ?? null,
                    'playtime_hours'  => $playtimeHours,
                    'completed_count' => $completed,
                    'total_owned'     => $totalOwned,
                    'completion_rate' => $completionRate,
                    'is_fake'         => false,
                ];
            }
        }

        // Merge fake entries from seed file
        $fakePath = $this->projectDir . '/config/seeds/fake_leaderboard.json';
        if (file_exists($fakePath)) {
            $fakeData = json_decode(file_get_contents($fakePath), true) ?? [];
            foreach ($fakeData as $fake) {
                $entries[] = [
                    'profile'         => null,
                    'player'          => ['personaname' => $fake['name']],
                    'playtime_hours'  => $fake['playtime_hours'],
                    'completed_count' => $fake['completed_count'],
                    'total_owned'     => $fake['total_owned'],
                    'completion_rate' => $fake['completion_rate'],
                    'is_fake'         => true,
                ];
            }
        }

        return $this->render('leaderboard/index.html.twig', [
            'entries'    => $entries,
            'my_profile' => $myProfile,
        ]);
    }

    #[Route('/leaderboard/opt-in', name: 'leaderboard_opt_in', methods: ['POST'])]
    public function optIn(Request $request): Response
    {
        $token = $this->userTokenService->getToken($request);
        if ($token === null) {
            return $this->redirectToRoute('leaderboard');
        }

        $profile = $this->userProfileRepository->findOneBy(['userToken' => $token]);
        if ($profile === null) {
            return $this->redirectToRoute('leaderboard');
        }

        $optIn = $request->request->getBoolean('show_in_leaderboard', false);
        $profile->setShowInLeaderboard($optIn);
        $this->em->flush();

        return $this->redirectToRoute('leaderboard');
    }
}

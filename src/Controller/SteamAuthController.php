<?php

namespace App\Controller;

use App\Entity\GameCompletion;
use App\Entity\UserProfile;
use App\Enum\GameStatus;
use App\Repository\GameCompletionRepository;
use App\Repository\HltbCacheRepository;
use App\Repository\UserProfileRepository;
use App\Service\SteamApiService;
use App\Service\UserTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SteamAuthController extends AbstractController
{
    public function __construct(
        private readonly SteamApiService $steamApiService,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly HltbCacheRepository $hltbCacheRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserTokenService $userTokenService,
        private readonly string $inviteToken = '',
    ) {}

    #[Route('/auth/steam', name: 'steam_auth', methods: ['GET'])]
    public function login(Request $request): RedirectResponse
    {
        $callbackUrl = $this->generateUrl('steam_auth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $params = [
            'openid.ns'         => 'http://specs.openid.net/auth/2.0',
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $callbackUrl,
            'openid.realm'      => $request->getSchemeAndHttpHost(),
            'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];

        return new RedirectResponse('https://steamcommunity.com/openid/login?' . http_build_query($params));
    }

    #[Route('/auth/steam/callback', name: 'steam_auth_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        // PHP converts dots to underscores in $_GET (openid.mode → openid_mode),
        // so we must parse the raw query string ourselves to preserve the dot keys.
        $params = [];
        foreach (explode('&', $request->getQueryString() ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2) + ['', ''];
            $params[urldecode($key)] = urldecode($value);
        }

        $steamId = $this->steamApiService->verifyOpenIdCallback($params);
        if ($steamId === null) {
            return $this->redirectToRoute('game_library_home');
        }

        $isNewUser = false;
        $profile   = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            $isNewUser = true;
            $token     = $this->userTokenService->generateToken();

            $profile = new UserProfile();
            $profile->setUserToken($token);
            $profile->setSteamId($steamId);
            $profile->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($profile);

            // Claim any pre-existing unclaimed rows (handles migration from single-user setup)
            $this->gameCompletionRepository->claimUnownedRows($token);

            $this->entityManager->flush();
        }

        if ($isNewUser) {
            // New user: redirect to loading page - it will fetch the import endpoint and
            // then forward to the library once done.
            $response = $this->redirectToRoute('steam_auth_setup', ['steamId' => $steamId]);
        } else {
            $response = $this->redirectToRoute('game_library', ['steamId' => $steamId]);
        }
        $this->userTokenService->setTokenCookie($response, $profile->getUserToken());
        return $response;
    }

    #[Route('/auth/steam/setup/{steamId}', name: 'steam_auth_setup', requirements: ['steamId' => '\d{17}'], methods: ['GET'])]
    public function setup(string $steamId, Request $request): Response
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->redirectToRoute('game_library_home');
        }

        $token = $this->userTokenService->getToken($request);
        if ($token === null || $token !== $profile->getUserToken()) {
            return $this->redirectToRoute('game_library_home');
        }

        return $this->render('auth/setup.html.twig', ['steam_id' => $steamId]);
    }

    #[Route('/auth/steam/import-games/{steamId}', name: 'steam_auth_import', requirements: ['steamId' => '\d{17}'], methods: ['POST'])]
    public function importGames(string $steamId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $token = $this->userTokenService->getToken($request);
        if ($token === null || $token !== $profile->getUserToken()) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $result = $this->autoImportGames($steamId, $token);
        return $this->json($result);
    }

    #[Route('/auth/invite/{token}', name: 'steam_auth_invite', methods: ['GET', 'POST'])]
    public function invite(string $token, Request $request): Response
    {
        if ($this->inviteToken === '' || $token !== $this->inviteToken) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('GET')) {
            return $this->render('auth/invite.html.twig', ['error' => null]);
        }

        $steamInput = trim($request->request->getString('steam_url'));
        if ($steamInput === '') {
            return $this->render('auth/invite.html.twig', ['error' => 'Please enter your Steam profile URL.']);
        }

        try {
            $steamId = $this->steamApiService->resolveSteamInput($steamInput);
        } catch (\RuntimeException) {
            return $this->render('auth/invite.html.twig', ['error' => 'Could not connect to Steam. Please try again.']);
        }

        if ($steamId === null) {
            return $this->render('auth/invite.html.twig', [
                'error' => 'Could not find that Steam profile. Make sure it\'s set to public.',
            ]);
        }

        $profile = $this->userProfileRepository->findOneBy(['steamId' => $steamId]);
        if ($profile === null) {
            $newToken = $this->userTokenService->generateToken();

            $profile = new UserProfile();
            $profile->setUserToken($newToken);
            $profile->setSteamId($steamId);
            $profile->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($profile);
            $this->entityManager->flush();

            $this->autoImportGames($steamId, $newToken);
        }

        $response = $this->redirectToRoute('game_library', ['steamId' => $steamId]);
        $this->userTokenService->setTokenCookie($response, $profile->getUserToken());
        return $response;
    }

    /**
     * Creates Playing/Completed records for every game the user has played, but only if they
     * have no existing tracking data (i.e. they are genuinely brand new).
     *
     * Classification logic:
     *  - playtime >= hltb_hours * 0.82  →  Completed (with completedAt)
     *  - otherwise                       →  Playing
     *
     * Returns ['total' => int, 'completed' => int].
     */
    private function autoImportGames(string $steamId, string $userToken): array
    {
        // Skip if the user already has any tracked games (e.g. claimed via claimUnownedRows)
        if ($this->gameCompletionRepository->findOneBy(['userToken' => $userToken]) !== null) {
            return ['total' => 0, 'completed' => 0];
        }

        try {
            $games = $this->steamApiService->getOwnedGames($steamId);
        } catch (\RuntimeException) {
            return ['total' => 0, 'completed' => 0]; // Steam API unavailable - skip silently
        }

        // Pre-load all HLTB data we have cached for this game set
        $playedGames = array_filter($games, fn($g) => ($g['playtime_forever'] ?? 0) > 0);
        $appIds      = array_column($playedGames, 'appid');
        $hltbMap     = $this->hltbCacheRepository->findByAppIds($appIds);

        $total     = 0;
        $completed = 0;
        $now       = new \DateTimeImmutable();

        foreach ($playedGames as $game) {
            $appId        = $game['appid'];
            $playtimeMins = $game['playtime_forever'];
            $playtimeHrs  = $playtimeMins / 60.0;

            $hltbEntry  = $hltbMap[$appId] ?? null;
            $hltbHours  = $hltbEntry?->getHoursMain();

            // Classify as Completed when playtime covers at least 82% of the HLTB main story
            $isCompleted = $hltbHours !== null
                        && $hltbHours > 0
                        && $playtimeHrs >= $hltbHours * 0.82;

            $completion = new GameCompletion();
            $completion->setAppId($appId)
                       ->setUserToken($userToken)
                       ->setStatus($isCompleted ? GameStatus::Completed : GameStatus::Playing);

            if ($isCompleted) {
                $completion->setCompletedAt($now);
                $completed++;
            }

            $this->entityManager->persist($completion);
            $total++;
        }

        if ($total > 0) {
            $this->entityManager->flush();
        }

        return ['total' => $total, 'completed' => $completed];
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(Request $request): Response
    {
        $request->getSession()->invalidate();
        $response = $this->redirectToRoute('game_library_home');
        $this->userTokenService->clearTokenCookie($response);
        return $response;
    }
}

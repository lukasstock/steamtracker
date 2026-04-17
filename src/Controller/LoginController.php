<?php

namespace App\Controller;

use App\Entity\UserProfile;
use App\Repository\GameCompletionRepository;
use App\Repository\UserProfileRepository;
use App\Service\UserTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoginController extends AbstractController
{
    public function __construct(
        private readonly string $appPassword,
        private readonly string $steamId,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly GameCompletionRepository $gameCompletionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserTokenService $userTokenService,
    ) {}

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if ($request->request->get('password') !== $this->appPassword) {
                return $this->render('login.html.twig', ['error' => 'Wrong password.']);
            }

            $profile = $this->userProfileRepository->findOneBy(['steamId' => $this->steamId]);
            if ($profile === null) {
                // First login: bootstrap the owner profile and claim all pre-existing rows
                $token = $this->userTokenService->generateToken();

                $profile = new UserProfile();
                $profile->setUserToken($token);
                $profile->setSteamId($this->steamId);
                $profile->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($profile);

                $this->gameCompletionRepository->claimUnownedRows($token);

                $this->entityManager->flush();
            }

            $response = $this->redirectToRoute('game_library', ['steamId' => $this->steamId]);
            $this->userTokenService->setTokenCookie($response, $profile->getUserToken());
            return $response;
        }

        return $this->render('login.html.twig', ['error' => null]);
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

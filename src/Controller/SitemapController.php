<?php

namespace App\Controller;

use App\Repository\UserProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SitemapController extends AbstractController
{
    public function __construct(
        private readonly UserProfileRepository $userProfileRepository,
    ) {}

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $profiles = $this->userProfileRepository->findAll();

        $response = $this->render('sitemap.xml.twig', [
            'base_url' => $request->getSchemeAndHttpHost(),
            'profiles' => $profiles,
        ]);

        $response->headers->set('Content-Type', 'application/xml');
        return $response;
    }
}

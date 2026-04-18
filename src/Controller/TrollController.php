<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TrollController extends AbstractController
{
    #[Route('/steamlibrary', name: 'troll_page', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('troll/index.html.twig');
    }
}

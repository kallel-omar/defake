<?php

namespace App\Controller;

use App\Repository\PostCheckRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin')]
    public function index(
        PostCheckRepository $postCheckRepository,
        UserRepository $userRepository
    ): Response {
        return $this->render('admin/index.html.twig', [
            'totalChecks' => $postCheckRepository->count([]),
            'totalUsers' => $userRepository->count([]),
            'latestChecks' => $postCheckRepository->findBy([], ['createdAt' => 'DESC'], 10),
        ]);
    }
}
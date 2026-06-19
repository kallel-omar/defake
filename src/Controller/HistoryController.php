<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PostCheckRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history')]
    public function index(PostCheckRepository $postCheckRepository): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            $this->addFlash('error', 'Please log in to view your analysis history.');

            return $this->redirectToRoute('app_login');
        }

        $postChecks = $postCheckRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('history/index.html.twig', [
            'postChecks' => $postChecks,
        ]);
    }
}
<?php

namespace App\Controller;

use App\Entity\PostCheck;
use App\Repository\PostCheckRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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
            'latestChecks' => $postCheckRepository->findBy([], ['createdAt' => 'DESC'], 20),
        ]);
    }

    #[Route('/admin/check/{id}/delete', name: 'app_admin_check_delete', methods: ['POST'])]
    public function deleteCheck(
        PostCheck $postCheck,
        Request $request,
        EntityManagerInterface $entityManager
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('delete_check_' . $postCheck->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');

            return $this->redirectToRoute('app_admin');
        }

        $entityManager->remove($postCheck);
        $entityManager->flush();

        $this->addFlash('success', 'Search deleted successfully.');

        return $this->redirectToRoute('app_admin');
    }
}
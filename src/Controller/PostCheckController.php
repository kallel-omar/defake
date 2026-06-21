<?php

namespace App\Controller;

use App\Entity\PostCheck;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PostCheckController extends AbstractController
{
    #[Route('/check/{id}', name: 'app_post_check_show')]
public function show(PostCheck $postCheck): Response
{
    $owner = $postCheck->getUser();
    $currentUser = $this->getUser();

    // Public guest analyses can be viewed by anyone
    if ($owner === null) {
        return $this->render('post_check/show.html.twig', [
            'postCheck' => $postCheck,
        ]);
    }

    // Admin can view all analyses
    if ($this->isGranted('ROLE_ADMIN')) {
        return $this->render('post_check/show.html.twig', [
            'postCheck' => $postCheck,
        ]);
    }

    // Normal users can only view their own analyses
    if ($owner !== $currentUser) {
        throw $this->createAccessDeniedException('You cannot access this analysis.');
    }

    return $this->render('post_check/show.html.twig', [
        'postCheck' => $postCheck,
    ]);
}


    #[Route('/check/{id}/delete', name: 'app_post_check_delete', methods: ['POST'])]
    public function delete(PostCheck $postCheck, EntityManagerInterface $em): RedirectResponse
    {
        if ($postCheck->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot delete this analysis.');
        }

        $em->remove($postCheck);
        $em->flush();

        $this->addFlash('success', 'Analysis deleted successfully.');

        return $this->redirectToRoute('app_history');
    }
}
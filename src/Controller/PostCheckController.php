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
    // DeFake analyses are based on public Facebook posts.
    // Result pages are reusable so duplicate URL submissions can redirect safely.
    // User history remains private elsewhere, and admin debug data is still protected in Twig.
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
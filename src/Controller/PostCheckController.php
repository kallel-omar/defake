<?php

namespace App\Controller;

use App\Entity\PostCheck;
use App\Security\Voter\PostCheckVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PostCheckController extends AbstractController
{
    #[Route('/check/{id}', name: 'app_post_check_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(PostCheck $postCheck): Response
    {
        if (!$this->isGranted(PostCheckVoter::VIEW, $postCheck)) {
            throw $this->createAccessDeniedException('You cannot view this analysis.');
        }

        return $this->render('post_check/show.html.twig', [
            'postCheck' => $postCheck,
        ]);
    }

    #[Route('/check/{id}/public/{token}', name: 'app_post_check_public_show', requirements: ['id' => '\d+', 'token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function publicShow(PostCheck $postCheck, string $token): Response
    {
        $publicToken = $postCheck->getPublicToken();

        if (!$publicToken || !hash_equals($publicToken, $token)) {
            throw $this->createNotFoundException('Analysis result not found.');
        }

        return $this->render('post_check/show.html.twig', [
            'postCheck' => $postCheck,
        ]);
    }

    #[Route('/check/{id}/delete', name: 'app_post_check_delete', methods: ['POST'])]
    public function delete(PostCheck $postCheck, Request $request, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isGranted(PostCheckVoter::DELETE, $postCheck)) {
            throw $this->createAccessDeniedException('You cannot delete this analysis.');
        }

        if (!$this->isCsrfTokenValid('delete_check_' . $postCheck->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_history');
        }

        $em->remove($postCheck);
        $em->flush();

        $this->addFlash('success', 'Analysis deleted successfully.');

        return $this->redirectToRoute('app_history');
    }
}

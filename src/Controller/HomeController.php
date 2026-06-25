<?php

namespace App\Controller;

use App\Entity\PostCheck;
use App\Entity\User;
use App\Message\AnalyzePostMessage;
use App\Repository\PostCheckRepository;
use App\Service\AnalysisUsageLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        PostCheckRepository $postCheckRepository,
        MessageBusInterface $bus,
        AnalysisUsageLimiter $analysisUsageLimiter
    ): Response {
        if ($request->isMethod('POST')) {
            $user = $this->getUser();
            $currentUser = $user instanceof User ? $user : null;
            $ip = $request->getClientIp() ?? 'anonymous';

            $url = trim((string) $request->request->get('url'));
            $postText = trim((string) $request->request->get('post_text'));

            if (!$this->isSupportedFacebookPost($url)) {
                $this->addFlash(
                    'error',
                    'DeFake currently supports only public Facebook post links.'
                );

                return $this->redirectToRoute('app_home');
            }

            $urlHash = hash('sha256', $url);

            $existingPostCheck = $postCheckRepository->findOneBy([
                'urlHash' => $urlHash,
            ]);

            if ($existingPostCheck) {
                $this->addFlash(
                    'info',
                    'This link was already submitted. Showing existing result.'
                );

                return $this->redirectToRoute('app_post_check_show', [
                    'id' => $existingPostCheck->getId(),
                ]);
            }

            if (!$analysisUsageLimiter->canAnalyze($currentUser, $ip)) {
                if ($currentUser) {
                    $this->addFlash(
                        'error',
                        'Daily limit reached. You can analyze 10 posts per day. Membership plans are coming soon.'
                    );
                } else {
                    $this->addFlash(
                        'error',
                        'You used your 5 free checks. Create a free account to continue and get up to 10 total checks per day.'
                    );
                }

                return $this->redirectToRoute('app_home');
            }

            $postCheck = new PostCheck();
            $postCheck->setUrl($url);
            $postCheck->setUrlHash($urlHash);
            $postCheck->setPlatform($this->detectPlatform($url));
            $postCheck->setUser($currentUser);
            $postCheck->setContent($postText !== '' ? $postText : null);
            $postCheck->setStatus('processing');
            $postCheck->setProcessingStep('Request received');
            $postCheck->setScore(0);
            $postCheck->setVerdict('PROCESSING');
            $postCheck->setExplanation('Analysis is currently processing.');
            $postCheck->setCreatedAt(new \DateTimeImmutable());

            $em->persist($postCheck);
            $analysisUsageLimiter->registerUsage($currentUser, $ip);
            $em->flush();

            $bus->dispatch(new AnalyzePostMessage($postCheck->getId()));

            return $this->redirectToRoute('app_post_check_show', [
                'id' => $postCheck->getId(),
            ]);
        }

        return $this->render('home/index.html.twig');
    }

    private function detectPlatform(string $url): string
    {
        return 'Facebook';
    }

    private function isSupportedFacebookPost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if (!$host) {
            return false;
        }

        $host = strtolower($host);
        $path = strtolower($path);

        if (
            !str_contains($host, 'facebook.com') &&
            !str_contains($host, 'www.facebook.com') &&
            !str_contains($host, 'm.facebook.com')
        ) {
            return false;
        }

        $rejectedPaths = [
            '/groups/',
            '/reel/',
            '/reels/',
            '/watch/',
            '/marketplace/',
            '/events/',
            '/gaming/',
        ];

        foreach ($rejectedPaths as $rejectedPath) {
            if (str_contains($path, $rejectedPath)) {
                return false;
            }
        }

        return true;
    }
}
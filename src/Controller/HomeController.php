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
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/check/facebook', name: 'app_facebook_check', methods: ['GET', 'POST'])]
    public function facebookCheck(
        Request $request,
        EntityManagerInterface $em,
        PostCheckRepository $postCheckRepository,
        MessageBusInterface $bus,
        AnalysisUsageLimiter $analysisUsageLimiter
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('facebook_check', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token. Please try again.');

                return $this->redirectToRoute('app_facebook_check');
            }

            $user = $this->getUser();
            $currentUser = $user instanceof User ? $user : null;
            $ip = $request->getClientIp() ?? 'anonymous';
            $isAdmin = $this->isGranted('ROLE_ADMIN');

            $submittedUrl = trim((string) $request->request->get('url'));
            $postText = trim((string) $request->request->get('post_text'));

            if (!$this->isSupportedFacebookPost($submittedUrl)) {
                $this->addFlash(
                    'error',
                    'DeFake currently supports only public Facebook post links.'
                );

               return $this->redirectToRoute('app_facebook_check');
            }

            $url = $this->normalizeFacebookUrl($submittedUrl);
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

            if (!$isAdmin && !$analysisUsageLimiter->canAnalyze($currentUser, $ip)) {
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

                return $this->redirectToRoute('app_facebook_check');
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

            if (!$isAdmin) {
                $analysisUsageLimiter->registerUsage($currentUser, $ip);
            }

            $em->flush();

            $bus->dispatch(new AnalyzePostMessage($postCheck->getId()));

            return $this->redirectToRoute('app_post_check_show', [
                'id' => $postCheck->getId(),
            ]);
        }

        return $this->render('home/facebook_check.html.twig');
    }

    #[Route('/check/text', name: 'app_text_check', methods: ['GET', 'POST'])]
    public function textCheck(
        Request $request,
        EntityManagerInterface $em,
        PostCheckRepository $postCheckRepository,
        MessageBusInterface $bus,
        AnalysisUsageLimiter $analysisUsageLimiter
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('text_check', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token. Please try again.');

                return $this->redirectToRoute('app_text_check');
            }

            $user = $this->getUser();
            $currentUser = $user instanceof User ? $user : null;
            $ip = $request->getClientIp() ?? 'anonymous';
            $isAdmin = $this->isGranted('ROLE_ADMIN');

            $submittedText = $this->normalizeManualText(
                (string) $request->request->get('claim_text')
            );

            if ($submittedText === '') {
                $this->addFlash('error', 'Please enter a claim or news text to check.');

                return $this->redirectToRoute('app_text_check');
            }

            if (mb_strlen($submittedText) < 8) {
                $this->addFlash('error', 'The text is too short to verify safely.');

                return $this->redirectToRoute('app_text_check');
            }

            if (mb_strlen($submittedText) > 5000) {
                $this->addFlash('error', 'The text is too long. Please submit a shorter claim or news paragraph.');

                return $this->redirectToRoute('app_text_check');
            }

            $textHash = hash('sha256', mb_strtolower($submittedText));
            $internalUrl = 'text://manual/' . substr($textHash, 0, 32);
            $urlHash = hash('sha256', $internalUrl);

            $existingPostCheck = $postCheckRepository->findOneBy([
                'urlHash' => $urlHash,
            ]);

            if ($existingPostCheck) {
                $this->addFlash(
                    'info',
                    'This text was already checked. Showing existing result.'
                );

                return $this->redirectToRoute('app_post_check_show', [
                    'id' => $existingPostCheck->getId(),
                ]);
            }

            if (!$isAdmin && !$analysisUsageLimiter->canAnalyze($currentUser, $ip)) {
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

                return $this->redirectToRoute('app_text_check');
            }

            $postCheck = new PostCheck();
            $postCheck->setUrl($internalUrl);
            $postCheck->setUrlHash($urlHash);
            $postCheck->setPlatform('Text');
            $postCheck->setUser($currentUser);
            $postCheck->setContent($submittedText);
            $postCheck->setContentType('manual_text');
            $postCheck->setStatus('processing');
            $postCheck->setProcessingStep('Text received');
            $postCheck->setScore(0);
            $postCheck->setVerdict('PROCESSING');
            $postCheck->setExplanation('Analysis is currently processing.');
            $postCheck->setCreatedAt(new \DateTimeImmutable());

            $em->persist($postCheck);

            if (!$isAdmin) {
                $analysisUsageLimiter->registerUsage($currentUser, $ip);
            }

            $em->flush();

            $bus->dispatch(new AnalyzePostMessage($postCheck->getId()));

            return $this->redirectToRoute('app_post_check_show', [
                'id' => $postCheck->getId(),
            ]);
        }

        return $this->render('home/text_check.html.twig');
    }

    private function normalizeManualText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function detectPlatform(string $url): string
    {
        return 'Facebook';
    }

    private function normalizeFacebookUrl(string $url): string
    {
        $url = trim($url);

        $parts = parse_url($url);

        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^(www\.|m\.|mobile\.)/', '', $host) ?? $host;

        if (!$this->isFacebookHost($host)) {
            return $url;
        }

        $path = $parts['path'] ?? '';
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        if ($path === '') {
            $path = '/';
        }

        $allowedQueryKeys = [
            'story_fbid',
            'id',
            'fbid',
            'comment_id',
            'reply_comment_id',
        ];

        $queryParams = [];

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);

            $queryParams = array_filter(
                $queryParams,
                static fn ($key) => in_array($key, $allowedQueryKeys, true),
                ARRAY_FILTER_USE_KEY
            );

            ksort($queryParams);
        }

        $normalized = 'https://www.facebook.com' . $path;

        if (!empty($queryParams)) {
            $normalized .= '?' . http_build_query($queryParams);
        }

        return $normalized;
    }

    private function isFacebookHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'facebook.com' || str_ends_with($host, '.facebook.com');
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

        if (!$this->isFacebookHost($host)) {
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

<?php

namespace App\MessageHandler;

use App\Message\AnalyzePostMessage;
use App\Repository\PostCheckRepository;
use App\Service\ApifyFacebookExtractorService;
use App\Service\ExternalLinkExtractorService;
use App\Service\PostAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AnalyzePostMessageHandler
{
    public function __construct(
        private readonly PostCheckRepository $postCheckRepository,
        private readonly EntityManagerInterface $em,
        private readonly ApifyFacebookExtractorService $facebookPostExtractorService,
        private readonly PostAnalysisService $postAnalysisService,
        private readonly ExternalLinkExtractorService $externalLinkExtractorService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AnalyzePostMessage $message): void
    {
        $postCheck = $this->postCheckRepository->find($message->getPostCheckId());

        if (!$postCheck) {
            return;
        }

        try {
            $url = $postCheck->getUrl();
            $postText = trim((string) $postCheck->getContent());

            $postCheck->setStatus('processing');
            $postCheck->setProcessingStep('Extracting Facebook post');
            $this->em->flush();

            $extraction = $this->facebookPostExtractorService->extract($url);

            if ($extraction['status'] === ApifyFacebookExtractorService::PRIVATE_POST) {
                $this->markAsFailed(
                    $postCheck,
                    'PRIVATE_POST',
                    'This Facebook post is not public. DeFake can only analyze publicly accessible posts.'
                );

                return;
            }

            $linkUrls = [];

            if ($extraction['status'] === 'ok') {
                $linkUrls = $this->filterExternalLinks($extraction['links'] ?? []);

                if ($postText === '' && !empty($extraction['text'])) {
                    $postText = trim((string) $extraction['text']);
                    $postCheck->setContent($postText);
                }
            }

            if ($postText === '') {
                $this->markAsFailed(
                    $postCheck,
                    'EXTRACTION_FAILED',
                    'Could not automatically extract the Facebook post text. Please paste the post text manually.'
                );

                return;
            }

            if (!empty($linkUrls)) {
                $postCheck->setProcessingStep('Reading linked article');
                $this->em->flush();

                $linkedContentText = '';

                foreach (array_slice($linkUrls, 0, 3) as $linkUrl) {
                    $linkedPage = $this->externalLinkExtractorService->extract($linkUrl);

                    if (!empty($linkedPage['title']) || !empty($linkedPage['content'])) {
                        $linkedContentText .= "\n\nLinked page URL: " . $linkUrl;
                        $linkedContentText .= "\nLinked page title: " . ($linkedPage['title'] ?: 'No title');
                        $linkedContentText .= "\nLinked page content: " . ($linkedPage['content'] ?: 'No readable content');
                    }
                }

                if ($linkedContentText !== '') {
                    $postText .= "\n\nAdditional context from links:" . $linkedContentText;
                    $postCheck->setContent($postText);
                    $this->em->flush();
                }
            }

            $postCheck->setProcessingStep('Extracting claim and checking evidence');
            $this->em->flush();

            $result = $this->postAnalysisService->analyze(
                $url,
                $postText,
                $extraction['sourceContext'] ?? []
            );

            $postCheck->setStatus('completed');
            $postCheck->setProcessingStep('Completed');

           $postCheck->setScore($result['score'] ?? 0);
$postCheck->setVerdict($result['verdict'] ?? 'Analysis Failed');
$postCheck->setExplanation($result['explanation'] ?? 'No explanation provided.');
$postCheck->setMainClaim($result['mainClaim'] ?? null);

// Compact 04B debugging metadata.
// Do not store raw prompts, raw AI responses, or full scraped Facebook JSON.
$scoreBreakdown = is_array($result['scoreBreakdown'] ?? null)
    ? $result['scoreBreakdown']
    : [];

$capsApplied = is_array($result['capsApplied'] ?? null)
    ? array_values($result['capsApplied'])
    : [];

$postCheck->setScoringVersion($result['scoringVersion'] ?? null);
$postCheck->setScoreBreakdown($scoreBreakdown ?: null);
$postCheck->setEvidenceDecision($result['evidenceDecision'] ?? null);
$postCheck->setSourceDecision($result['sourceDecision'] ?? null);
$postCheck->setRiskDecision($result['riskDecision'] ?? null);
$postCheck->setCapsApplied($capsApplied ?: null);

$postCheck->setEvidenceScore(
    (int) ($scoreBreakdown['evidenceMatch']['score'] ?? $result['evidenceScore'] ?? 0)
);

$postCheck->setSourceScore(
    (int) ($scoreBreakdown['sourceAuthority']['score'] ?? $result['sourceScore'] ?? 0)
);

$postCheck->setLanguageScore(
    (int) ($scoreBreakdown['sourceIndependence']['score'] ?? $result['languageScore'] ?? 0)
);

$postCheck->setVerificationScore(
    (int) ($scoreBreakdown['riskSafety']['score'] ?? $result['verificationScore'] ?? 0)
);

$postCheck->setEvidenceReason(
    $scoreBreakdown['evidenceMatch']['reason'] ?? $result['evidenceReason'] ?? null
);

$postCheck->setSourceReason(
    $scoreBreakdown['sourceAuthority']['reason'] ?? $result['sourceReason'] ?? null
);

$postCheck->setLanguageReason(
    $scoreBreakdown['sourceIndependence']['reason'] ?? $result['languageReason'] ?? null
);

$postCheck->setVerificationReason(
    $scoreBreakdown['riskSafety']['reason'] ?? $result['verificationReason'] ?? null
);
            $postCheck->setEvidenceSources($result['evidenceSources'] ?? []);

            if (($result['verdict'] ?? null) === 'NOT_VERIFIABLE') {
                $postCheck->setContentType('non_verifiable_content');
                $postCheck->setContentTitle('No verifiable claim detected');
                $postCheck->setContentSummary($result['explanation'] ?? 'This post does not contain a clear factual claim.');
            }

            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Post analysis failed.', [
                'postCheckId' => $postCheck->getId(),
                'exception' => $e,
            ]);

            $this->markAsFailed(
                $postCheck,
                'FAILED',
                'Analysis failed because DeFake could not complete this request. Please try again later.'
            );

            return;
        }
    }

    private function filterExternalLinks(array $links): array
    {
        $externalLinks = [];

        foreach ($links as $link) {
            if (!is_string($link)) {
                continue;
            }

            $host = parse_url($link, PHP_URL_HOST);

            if (!$host) {
                continue;
            }

            $host = strtolower($host);

            if (
                str_contains($host, 'facebook.com') ||
                str_contains($host, 'm.facebook.com') ||
                str_contains($host, 'fb.watch') ||
                str_contains($host, 'fb.me')
            ) {
                continue;
            }

            $externalLinks[] = $link;
        }

        return array_values(array_unique($externalLinks));
    }

    private function markAsFailed($postCheck, string $verdict, string $explanation): void
    {
        $postCheck->setStatus('failed');
        $postCheck->setProcessingStep('Failed');
        $postCheck->setVerdict($verdict);
        $postCheck->setScore(0);
        $postCheck->setExplanation($explanation);

        $this->em->flush();
    }
}

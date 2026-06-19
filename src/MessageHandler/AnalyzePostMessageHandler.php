<?php

namespace App\MessageHandler;

use App\Message\AnalyzePostMessage;
use App\Repository\PostCheckRepository;
use App\Service\ApifyFacebookExtractorService;
use App\Service\ExternalLinkExtractorService;
use App\Service\PostAnalysisService;
use App\Service\PostClassifierService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AnalyzePostMessageHandler
{
    public function __construct(
        private readonly PostCheckRepository $postCheckRepository,
        private readonly EntityManagerInterface $em,
        private readonly ApifyFacebookExtractorService $facebookPostExtractorService,
        private readonly PostClassifierService $postClassifierService,
        private readonly PostAnalysisService $postAnalysisService,
        private readonly ExternalLinkExtractorService $externalLinkExtractorService
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

            $postCheck->setProcessingStep('Classifying content');
            $this->em->flush();

            $classification = $this->postClassifierService->classify($postText);

            $postCheck->setContentType($classification['type']);
            $postCheck->setContentTitle($classification['title']);
            $postCheck->setContentSummary($classification['summary']);

            if (!$classification['containsClaim']) {
                $postCheck->setStatus('completed');
                $postCheck->setProcessingStep('Completed');
                $postCheck->setScore(0);
                $postCheck->setVerdict('NOT_VERIFIABLE');

                $postCheck->setExplanation(
                    'This post does not contain a clear verifiable factual claim. Post type: '
                    . $classification['type']
                    . '. Reason: '
                    . $classification['reason']
                );

                $postCheck->setEvidenceScore(0);
                $postCheck->setSourceScore(0);
                $postCheck->setLanguageScore(0);
                $postCheck->setVerificationScore(0);

                $postCheck->setEvidenceReason(
                    'No evidence analysis was performed because the post is not a verifiable news claim.'
                );
                $postCheck->setSourceReason(
                    'No source analysis was performed because the post is not a verifiable news claim.'
                );
                $postCheck->setLanguageReason(
                    'No language credibility score was calculated for this type of content.'
                );
                $postCheck->setVerificationReason(
                    'Verification was skipped because the post appears to be opinion, personal content, advertisement, question, satire, or non-news content.'
                );

                $this->em->flush();

                return;
            }

            $postCheck->setProcessingStep('Searching sources');
            $this->em->flush();

            $postCheck->setProcessingStep('Generating AI verification');
            $this->em->flush();

        $result = $this->postAnalysisService->analyze(
    $url,
    $postText,
    $extraction['sourceContext'] ?? []
);

            $postCheck->setStatus('completed');
            $postCheck->setProcessingStep('Completed');
            $postCheck->setScore($result['score']);
            $postCheck->setVerdict($result['verdict']);
            $postCheck->setExplanation($result['explanation']);

            $postCheck->setEvidenceScore($result['evidenceScore'] ?? 0);
            $postCheck->setSourceScore($result['sourceScore'] ?? 0);
            $postCheck->setLanguageScore($result['languageScore'] ?? 0);
            $postCheck->setVerificationScore($result['verificationScore'] ?? 0);

            $postCheck->setEvidenceReason($result['evidenceReason'] ?? null);
            $postCheck->setSourceReason($result['sourceReason'] ?? null);
            $postCheck->setLanguageReason($result['languageReason'] ?? null);
            $postCheck->setVerificationReason($result['verificationReason'] ?? null);

            $this->em->flush();
        } catch (\Throwable $e) {
            $this->markAsFailed(
                $postCheck,
                'FAILED',
                'Analysis failed: ' . $e->getMessage()
            );

            throw $e;
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
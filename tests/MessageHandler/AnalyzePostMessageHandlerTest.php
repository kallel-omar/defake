<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\PostCheck;
use App\Message\AnalyzePostMessage;
use App\MessageHandler\AnalyzePostMessageHandler;
use App\Repository\PostCheckRepository;
use App\Service\ApifyFacebookExtractorService;
use App\Service\ExternalLinkExtractorService;
use App\Service\PostAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AnalyzePostMessageHandlerTest extends TestCase
{
    public function testManualContextIsPassedIntoPostAnalysisService(): void
    {
        $postCheck = (new PostCheck())
            ->setUrl('text://manual/context-test')
            ->setPlatform('Text')
            ->setContent('Mo2men Rahmani signed for two years.')
            ->setContentType('manual_text')
            ->setContextCountry('TN')
            ->setContextTopic('sports')
            ->setStatus('processing')
            ->setScore(0)
            ->setVerdict('PROCESSING')
            ->setExplanation('Analysis is currently processing.')
            ->setCreatedAt(new \DateTimeImmutable());

        $postCheckRepository = $this->createMock(PostCheckRepository::class);
        $postCheckRepository
            ->expects(self::once())
            ->method('find')
            ->with(123)
            ->willReturn($postCheck);

        $facebookExtractor = $this->createMock(ApifyFacebookExtractorService::class);
        $facebookExtractor
            ->expects(self::never())
            ->method('extract');

        $externalLinkExtractor = $this->createMock(ExternalLinkExtractorService::class);
        $externalLinkExtractor
            ->expects(self::never())
            ->method('extract');

        $postAnalysisService = new class extends PostAnalysisService {
            public array $arguments = [];

            public function __construct()
            {
            }

            public function analyze(string $url, string $postText, array $sourceContext = [], mixed ...$extra): array
            {
                $this->arguments = func_get_args();

                return [
                    'score' => 0,
                    'verdict' => 'NOT_VERIFIABLE',
                    'mainClaim' => null,
                    'evidenceSources' => [],
                    'scoreBreakdown' => null,
                    'capsApplied' => ['NO_CLEAR_CLAIM'],
                    'explanation' => 'No clear factual claim detected.',
                    'evidenceDecision' => 'NO_CLEAR_CLAIM',
                    'sourceDecision' => 'NOT_ANALYZED',
                    'riskDecision' => 'NOT_ANALYZED',
                ];
            }
        };

        $handler = new AnalyzePostMessageHandler(
            $postCheckRepository,
            $this->createMock(EntityManagerInterface::class),
            $facebookExtractor,
            $postAnalysisService,
            $externalLinkExtractor,
            $this->createMock(LoggerInterface::class),
        );

        $handler(new AnalyzePostMessage(123));

        self::assertSame('text://manual/context-test', $postAnalysisService->arguments[0] ?? null);
        self::assertSame('Mo2men Rahmani signed for two years.', $postAnalysisService->arguments[1] ?? null);
        self::assertSame([], $postAnalysisService->arguments[2] ?? null);
        self::assertSame([
            'country' => 'TN',
            'topic' => 'sports',
        ], $postAnalysisService->arguments[3] ?? null);
    }
}

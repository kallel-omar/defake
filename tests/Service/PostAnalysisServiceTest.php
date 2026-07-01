<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnalysisExplanationService04B;
use App\Service\ClaimExtractionService;
use App\Service\ClaimVerifiabilityService;
use App\Service\EvidenceDecisionService;
use App\Service\EvidenceFormatterService;
use App\Service\EvidenceSourceMetrics04B;
use App\Service\InternetEvidenceSearchService;
use App\Service\OfficialSourceDetectorService;
use App\Service\PostAnalysisService;
use App\Service\ScoreBreakdownBuilder;
use App\Service\ScoreCalculator04B;
use App\Service\SourceConfidenceService;
use App\Service\VerdictDecisionService04B;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PostAnalysisServiceTest extends TestCase
{
    private ClaimExtractionService&MockObject $claimExtractionService;
    private ClaimVerifiabilityService&MockObject $claimVerifiabilityService;

    protected function setUp(): void
    {
        $this->claimExtractionService = $this->createMock(ClaimExtractionService::class);
        $this->claimVerifiabilityService = $this->createMock(ClaimVerifiabilityService::class);
    }

    public function testAnalyzeReturnsNotVerifiableWithoutSearchingEvidence(): void
    {
        $url = 'https://facebook.com/example-post';
        $postText = 'برشا كلام عام وغضب بدون claim واضح';
        $sourceContext = [];

        $claimVerifiability = [
            'verifiable' => false,
            'reason' => 'No clear factual claim detected.',
        ];

        $this->claimExtractionService
            ->expects(self::once())
            ->method('extract')
            ->with($postText, [])
            ->willReturn(['']);

        $this->claimVerifiabilityService
            ->expects(self::once())
            ->method('assess')
            ->with('', $postText)
            ->willReturn($claimVerifiability);

        $service = new PostAnalysisService(
            $this->inertService(InternetEvidenceSearchService::class),
            $this->inertService(EvidenceFormatterService::class),
            $this->inertService(ScoreBreakdownBuilder::class),
            $this->inertService(ScoreCalculator04B::class),
            $this->inertService(VerdictDecisionService04B::class),
            $this->inertService(AnalysisExplanationService04B::class),
            $this->inertService(OfficialSourceDetectorService::class),
            $this->inertService(EvidenceDecisionService::class),
            $this->claimExtractionService,
            $this->claimVerifiabilityService,
        );

        $result = $service->analyze($url, $postText, $sourceContext);

        self::assertSame([
            'score' => 0,
            'verdict' => 'NOT_VERIFIABLE',
            'mainClaim' => null,
            'evidenceSources' => [],

            'scoringVersion' => '04B',
            'scoreBreakdown' => null,
            'claimVerifiability' => $claimVerifiability,
            'evidenceDecision' => 'NO_CLEAR_CLAIM',
            'sourceDecision' => 'NOT_ANALYZED',
            'riskDecision' => 'NOT_ANALYZED',
            'capsApplied' => ['NO_CLEAR_CLAIM'],

            'evidenceScore' => 0,
            'sourceScore' => 0,
            'languageScore' => 0,
            'verificationScore' => 0,

            'evidenceReason' => 'No evidence search was performed because no clear factual claim was detected.',
            'sourceReason' => 'Source analysis was skipped because the post is not a factual news claim.',
            'languageReason' => 'The post is not clear enough to be safely checked as a factual news claim.',
            'verificationReason' => 'No clear factual claim detected.',
            'explanation' => 'This post does not contain a clear verifiable factual claim. DeFake cannot safely check vague commentary, opinion, sarcasm, insults, emotional reactions, or future rumors without concrete details.',
        ], $result);
    }

    public function testAnalyzeAddsManualContextHintsToSearchQueryWhenPresent(): void
    {
        $postText = 'Mo2men Rahmani signed for two years.';
        $mainClaim = 'Mo2men Rahmani signed for two years.';
        $capturedQuery = '';

        $analysisContext = [
            'country' => 'TN',
            'topic' => 'sports',
        ];

        $service = $this->createSearchQueryCapturingService($postText, $mainClaim, $capturedQuery, $analysisContext);

        $service->analyze('text://manual/test', $postText, [], $analysisContext);

        self::assertStringContainsString("Claim to verify:\n" . $mainClaim, $capturedQuery);
        self::assertStringContainsString('Context country: TN', $capturedQuery);
        self::assertStringContainsString('Context topic: sports', $capturedQuery);
    }

    public function testGlobalCountryDoesNotAddCountrySpecificSearchHint(): void
    {
        $postText = 'Mo2men Rahmani signed for two years.';
        $mainClaim = 'Mo2men Rahmani signed for two years.';
        $capturedQuery = '';

        $analysisContext = [
            'country' => 'GLOBAL',
            'topic' => 'sports',
        ];

        $service = $this->createSearchQueryCapturingService($postText, $mainClaim, $capturedQuery, $analysisContext);

        $service->analyze('text://manual/test', $postText, [], $analysisContext);

        self::assertStringNotContainsString('Context country:', $capturedQuery);
        self::assertStringNotContainsString('GLOBAL', $capturedQuery);
    }

    public function testEmptyContextKeepsOldSearchQueryBehavior(): void
    {
        $postText = 'Mo2men Rahmani signed for two years.';
        $mainClaim = 'Mo2men Rahmani signed for two years.';
        $capturedQuery = '';

        $service = $this->createSearchQueryCapturingService($postText, $mainClaim, $capturedQuery, []);

        $service->analyze('text://manual/test', $postText, [], []);

        self::assertSame($postText . "\n\nClaim to verify:\n" . $mainClaim, $capturedQuery);
    }

    public function testContextIsNotMergedIntoExtractedClaim(): void
    {
        $postText = 'Mo2men Rahmani signed for two years.';
        $mainClaim = 'Mo2men Rahmani signed for two years.';
        $capturedQuery = '';

        $analysisContext = [
            'country' => 'TN',
            'topic' => 'sports',
        ];

        $service = $this->createSearchQueryCapturingService($postText, $mainClaim, $capturedQuery, $analysisContext);

        $service->analyze('text://manual/test', $postText, [], $analysisContext);

        self::assertStringNotContainsString('Tunisia', $mainClaim);
        self::assertStringNotContainsString('sports', mb_strtolower($mainClaim));
    }

    public function testScoringAndVerdictCollaboratorsDoNotAcceptAnalysisContext(): void
    {
        foreach ([
            [ScoreCalculator04B::class, 'calculateEvidenceMatchScore'],
            [ScoreCalculator04B::class, 'calculateSourceAuthorityScore'],
            [ScoreCalculator04B::class, 'calculateSourceIndependenceScore'],
            [ScoreCalculator04B::class, 'calculateRiskSafetyScore'],
            [VerdictDecisionService04B::class, 'decide'],
        ] as [$className, $methodName]) {
            $parameters = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionMethod($className, $methodName))->getParameters()
            );

            self::assertNotContains('analysisContext', $parameters);
            self::assertNotContains('contextCountry', $parameters);
            self::assertNotContains('contextTopic', $parameters);
        }
    }

    /**
     * Creates an object that satisfies a concrete service type without calling its constructor.
     *
     * This is safe for this characterization test because the NOT_VERIFIABLE path must return
     * before any evidence/search/scoring/verdict/explanation collaborator is used.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    private function inertService(string $className): object
    {
        return (new ReflectionClass($className))->newInstanceWithoutConstructor();
    }

    private function createSearchQueryCapturingService(
        string $postText,
        string $mainClaim,
        string &$capturedQuery,
        array $expectedAnalysisContext
    ): PostAnalysisService {
        $this->claimExtractionService
            ->expects(self::once())
            ->method('extract')
            ->with($postText, $expectedAnalysisContext)
            ->willReturn([$mainClaim]);

        $this->claimVerifiabilityService
            ->expects(self::once())
            ->method('assess')
            ->with($mainClaim, $postText)
            ->willReturn([
                'verifiable' => true,
                'claimType' => 'sports',
                'reason' => 'The claim is verifiable.',
            ]);

        $internetEvidenceSearchService = $this->createMock(InternetEvidenceSearchService::class);
        $internetEvidenceSearchService
            ->expects(self::once())
            ->method('search')
            ->willReturnCallback(static function (string $query, ?string $claim) use (&$capturedQuery, $mainClaim): array {
                $capturedQuery = $query;

                self::assertSame($mainClaim, $claim);

                return [
                    'items' => [
                        [
                            'title' => 'Relevant source',
                            'snippet' => 'Snippet about the claim.',
                            'link' => 'https://example.com/news',
                            'source' => 'Example',
                        ],
                    ],
                ];
            });

        $evidenceDecisionService = $this->createMock(EvidenceDecisionService::class);
        $evidenceDecisionService
            ->expects(self::once())
            ->method('decide')
            ->with($mainClaim, self::isType('array'))
            ->willReturn([
                'status' => 'SUPPORTED',
                'supportCount' => 0,
                'relevantIndexes' => [],
                'reason' => 'Test evidence decision.',
            ]);

        $officialSourceDetectorService = $this->createMock(OfficialSourceDetectorService::class);
        $officialSourceDetectorService
            ->expects(self::once())
            ->method('detect')
            ->with([], $postText)
            ->willReturn([
                'official' => false,
                'category' => 'unknown',
                'confidence' => 0,
                'reason' => 'No official source context.',
            ]);

        $evidenceSourceMetrics = new EvidenceSourceMetrics04B();
        $scoreCalculator = new ScoreCalculator04B($evidenceSourceMetrics);

        return new PostAnalysisService(
            $internetEvidenceSearchService,
            new EvidenceFormatterService(new SourceConfidenceService(), $officialSourceDetectorService),
            new ScoreBreakdownBuilder(),
            $scoreCalculator,
            new VerdictDecisionService04B($scoreCalculator, $evidenceSourceMetrics),
            new AnalysisExplanationService04B($evidenceSourceMetrics),
            $officialSourceDetectorService,
            $evidenceDecisionService,
            $this->claimExtractionService,
            $this->claimVerifiabilityService,
        );
    }
}

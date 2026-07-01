<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnalysisExplanationService04B;
use App\Service\ClaimExtractionService;
use App\Service\ClaimVerifiabilityService;
use App\Service\EvidenceDecisionService;
use App\Service\EvidenceFormatterService;
use App\Service\InternetEvidenceSearchService;
use App\Service\OfficialSourceDetectorService;
use App\Service\PostAnalysisService;
use App\Service\ScoreBreakdownBuilder;
use App\Service\ScoreCalculator04B;
use App\Service\VerdictDecisionService04B;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
            ->with($postText)
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
}
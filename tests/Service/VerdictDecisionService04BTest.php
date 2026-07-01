<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EvidenceSourceMetrics04B;
use App\Service\ScoreCalculator04B;
use App\Service\VerdictDecisionService04B;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerdictDecisionService04BTest extends TestCase
{
    #[DataProvider('provideDecideCases')]
    public function testDecideReturnsCurrentVerdictScoreAndCaps(
        array $scoreBreakdown,
        array $claimVerifiability,
        array $evidenceDecision,
        string $sourceDecision,
        string $riskDecision,
        array $officialSource,
        array $expected
    ): void {
        $service = $this->createService();

        self::assertSame(
            $expected,
            $service->decide(
                $scoreBreakdown,
                $claimVerifiability,
                $evidenceDecision,
                $sourceDecision,
                $riskDecision,
                $officialSource
            )
        );
    }

    #[DataProvider('provideDetectSourceDecisionCases')]
    public function testDetectSourceDecisionReturnsExpectedLabel(
        array $officialSource,
        array $evidenceItems,
        array $relevantIndexes,
        string $expected
    ): void {
        $service = $this->createService();

        self::assertSame(
            $expected,
            $service->detectSourceDecision($officialSource, $evidenceItems, $relevantIndexes)
        );
    }

    public static function provideDetectSourceDecisionCases(): iterable
    {
        yield 'official source returns primary official immediately' => [
            ['official' => true],
            [],
            [],
            'PRIMARY_OFFICIAL',
        ];

        yield 'no evidence returns unknown' => [
            ['official' => false],
            [],
            [],
            'UNKNOWN',
        ];

        yield 'confidence score 90 returns top source' => [
            ['official' => false],
            [
                ['confidenceScore' => 90],
            ],
            [],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
        ];

        yield 'confidence score 75 returns reputable media' => [
            ['official' => false],
            [
                ['confidenceScore' => 75],
            ],
            [],
            'REPUTABLE_MEDIA',
        ];

        yield 'confidence score 60 returns known media' => [
            ['official' => false],
            [
                ['confidenceScore' => 60],
            ],
            [],
            'KNOWN_MEDIA',
        ];

        yield 'confidence score 40 returns weak media' => [
            ['official' => false],
            [
                ['confidenceScore' => 40],
            ],
            [],
            'WEAK_MEDIA',
        ];

        yield 'confidence score 20 returns social or low authority source' => [
            ['official' => false],
            [
                ['confidenceScore' => 20],
            ],
            [],
            'SOCIAL_OR_LOW_AUTHORITY_SOURCE',
        ];

        yield 'confidence score below 20 returns unknown' => [
            ['official' => false],
            [
                ['confidenceScore' => 19],
            ],
            [],
            'UNKNOWN',
        ];

        yield 'highest relevant confidence score decides label' => [
            ['official' => false],
            [
                ['confidenceScore' => 20],
                ['confidenceScore' => 75],
                ['confidenceScore' => 40],
            ],
            [],
            'REPUTABLE_MEDIA',
        ];

        yield 'source score is used when confidence score is missing' => [
            ['official' => false],
            [
                ['sourceScore' => 75],
            ],
            [],
            'REPUTABLE_MEDIA',
        ];

        yield 'confidence score overrides source score even when lower' => [
            ['official' => false],
            [
                [
                    'confidenceScore' => 0,
                    'sourceScore' => 90,
                ],
            ],
            [],
            'UNKNOWN',
        ];

        yield 'relevant indexes select only matching evidence items' => [
            ['official' => false],
            [
                ['confidenceScore' => 90],
                ['confidenceScore' => 40],
            ],
            [1],
            'WEAK_MEDIA',
        ];

        yield 'missing relevant indexes return unknown' => [
            ['official' => false],
            [
                ['confidenceScore' => 90],
            ],
            [5],
            'UNKNOWN',
        ];
    }

    public static function provideDecideCases(): iterable
    {
        yield 'not verifiable claim returns NOT_VERIFIABLE immediately' => [
            self::scoreBreakdown(totalScore: 90),
            ['verifiable' => false],
            ['status' => 'SUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 0,
                'verdict' => 'NOT_VERIFIABLE',
                'capsApplied' => ['NO_CLEAR_CLAIM'],
            ],
        ];

        yield 'contradicted evidence caps high score to 25 and returns Likely Fake' => [
            self::scoreBreakdown(totalScore: 90),
            self::verifiableClaim(),
            ['status' => 'CONTRADICTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 25,
                'verdict' => 'Likely Fake',
                'capsApplied' => ['DIRECT_REFUTATION'],
            ],
        ];

        yield 'contradicted evidence keeps score below 25 when total score is lower' => [
            self::scoreBreakdown(totalScore: 20),
            self::verifiableClaim(),
            ['status' => 'CONTRADICTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 20,
                'verdict' => 'Likely Fake',
                'capsApplied' => ['DIRECT_REFUTATION'],
            ],
        ];

        yield 'lowercase contradicted status is normalized and direct refutation still applies' => [
            self::scoreBreakdown(totalScore: 90),
            self::verifiableClaim(),
            ['status' => 'contradicted'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 25,
                'verdict' => 'Likely Fake',
                'capsApplied' => ['DIRECT_REFUTATION'],
            ],
        ];

        yield 'score 80 returns Likely Trusted when no caps apply' => [
            self::scoreBreakdown(totalScore: 80),
            self::verifiableClaim(),
            ['status' => 'SUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 80,
                'verdict' => 'Likely Trusted',
                'capsApplied' => [],
            ],
        ];

        yield 'score 79 returns Suspicious' => [
            self::scoreBreakdown(totalScore: 79),
            self::verifiableClaim(),
            ['status' => 'SUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 79,
                'verdict' => 'Suspicious',
                'capsApplied' => [],
            ],
        ];

        yield 'score 40 returns Suspicious' => [
            self::scoreBreakdown(totalScore: 40),
            self::verifiableClaim(),
            ['status' => 'UNKNOWN'],
            'UNKNOWN',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 40,
                'verdict' => 'Suspicious',
                'capsApplied' => [],
            ],
        ];

        yield 'score 39 returns Likely Fake when no supported-source adjustment applies' => [
            self::scoreBreakdown(totalScore: 39),
            self::verifiableClaim(),
            ['status' => 'UNKNOWN'],
            'UNKNOWN',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 39,
                'verdict' => 'Likely Fake',
                'capsApplied' => [],
            ],
        ];

        yield 'supported fake with no usable evidence source is upgraded to Suspicious' => [
            self::scoreBreakdown(
                totalScore: 39,
                evidenceMatch: 15,
                sourceAuthority: 0,
                sourceIndependence: 0
            ),
            self::verifiableClaim(),
            ['status' => 'SUPPORTED'],
            'UNKNOWN',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 39,
                'verdict' => 'Suspicious',
                'capsApplied' => ['NO_USABLE_EVIDENCE_SOURCE'],
            ],
        ];

        yield 'likely trusted with weak evidence match is capped to Suspicious' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 39,
                sourceAuthority: 23,
                sourceIndependence: 12
            ),
            self::verifiableClaim(),
            ['status' => 'SUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['STRONG_TRUST_REQUIRES_MATCH_AND_AUTHORITY'],
            ],
        ];

        yield 'likely trusted with weak source authority is capped to Suspicious' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 42,
                sourceAuthority: 14,
                sourceIndependence: 12
            ),
            self::verifiableClaim(),
            ['status' => 'SUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['STRONG_TRUST_REQUIRES_MATCH_AND_AUTHORITY'],
            ],
        ];

        yield 'unrelated evidence cannot remain Likely Trusted' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 40,
                sourceAuthority: 23,
                sourceIndependence: 12
            ),
            self::verifiableClaim(),
            ['status' => 'UNRELATED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['DIFFERENT_CONTEXT_ONLY'],
            ],
        ];

        yield 'partially supported evidence cannot remain Likely Trusted' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 40,
                sourceAuthority: 23,
                sourceIndependence: 12
            ),
            self::verifiableClaim(),
            ['status' => 'PARTIALLY_SUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['PARTIAL_SUPPORT_ONLY'],
            ],
        ];

        yield 'unsupported evidence cannot remain Likely Trusted' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 40,
                sourceAuthority: 23,
                sourceIndependence: 12
            ),
            self::verifiableClaim(),
            ['status' => 'UNSUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['NO_DIRECT_SUPPORT'],
            ],
        ];

        yield 'unknown evidence status cannot remain Likely Trusted' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 40,
                sourceAuthority: 23,
                sourceIndependence: 12
            ),
            self::verifiableClaim(),
            ['status' => 'UNKNOWN'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['NO_DIRECT_SUPPORT'],
            ],
        ];

        yield 'serious claim with non-primary source below top authority is capped' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 42,
                sourceAuthority: 18,
                sourceIndependence: 12
            ),
            self::verifiableClaim('sports'),
            ['status' => 'SUPPORTED'],
            'REPUTABLE_MEDIA',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['SERIOUS_CLAIM_NEEDS_PRIMARY_OR_TOP_SOURCE'],
            ],
        ];

        yield 'serious claim with top source can remain Likely Trusted' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 42,
                sourceAuthority: 23,
                sourceIndependence: 12
            ),
            self::verifiableClaim('sports'),
            ['status' => 'SUPPORTED'],
            'PRIMARY_DOCUMENT_OR_TOP_SOURCE',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Likely Trusted',
                'capsApplied' => [],
            ],
        ];

        yield 'official self-announcement can remain trusted even with weak evidence match' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 15,
                sourceAuthority: 25,
                sourceIndependence: 10
            ),
            self::verifiableClaim('sports'),
            ['status' => 'SUPPORTED'],
            'UNKNOWN',
            'LOW_RISK',
            ['official' => true],
            [
                'score' => 85,
                'verdict' => 'Likely Trusted',
                'capsApplied' => [],
            ],
        ];

        yield 'strong trust cap shadows weak repeated rumors cap' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 42,
                sourceAuthority: 9,
                sourceIndependence: 6
            ),
            self::verifiableClaim(),
            ['status' => 'SUPPORTED'],
            'WEAK_MEDIA',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['STRONG_TRUST_REQUIRES_MATCH_AND_AUTHORITY'],
            ],
        ];

        yield 'strong trust cap shadows serious claim needs strong source cap' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 42,
                sourceAuthority: 14,
                sourceIndependence: 12
            ),
            self::verifiableClaim('sports'),
            ['status' => 'SUPPORTED'],
            'PRIMARY_DOCUMENT',
            'LOW_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['STRONG_TRUST_REQUIRES_MATCH_AND_AUTHORITY'],
            ],
        ];

        yield 'strong trust cap shadows high risk with weak evidence cap' => [
            self::scoreBreakdown(
                totalScore: 85,
                evidenceMatch: 39,
                sourceAuthority: 14,
                sourceIndependence: 12,
                riskSafety: 3
            ),
            self::verifiableClaim(),
            ['status' => 'SUPPORTED'],
            'WEAK_MEDIA',
            'HIGH_RISK',
            ['official' => false],
            [
                'score' => 85,
                'verdict' => 'Suspicious',
                'capsApplied' => ['STRONG_TRUST_REQUIRES_MATCH_AND_AUTHORITY'],
            ],
        ];
    }

    private static function scoreBreakdown(
        int $totalScore,
        int $evidenceMatch = 42,
        int $sourceAuthority = 23,
        int $sourceIndependence = 12,
        int $riskSafety = 9
    ): array {
        return [
            'evidenceMatch' => ['score' => $evidenceMatch],
            'sourceAuthority' => ['score' => $sourceAuthority],
            'sourceIndependence' => ['score' => $sourceIndependence],
            'riskSafety' => ['score' => $riskSafety],
            'total' => ['score' => $totalScore],
        ];
    }

    private static function verifiableClaim(string $claimType = 'unknown'): array
    {
        return [
            'verifiable' => true,
            'claimType' => $claimType,
        ];
    }

    private function createService(): VerdictDecisionService04B
    {
        $evidenceSourceMetrics04B = new EvidenceSourceMetrics04B();

        return new VerdictDecisionService04B(
            new ScoreCalculator04B($evidenceSourceMetrics04B),
            $evidenceSourceMetrics04B
        );
    }
}
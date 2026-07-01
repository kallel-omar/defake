<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;

use App\Service\EvidenceSourceMetrics04B;
use App\Service\ScoreCalculator04B;
use PHPUnit\Framework\TestCase;


final class ScoreCalculator04BTest extends TestCase
{
    #[DataProvider('provideEvidenceMatchScoreCases')]
public function testCalculateEvidenceMatchScoreReturnsCurrentScore(
        ?string $status,
        bool $verificationContextSafe,
        array $formattedEvidenceSources,
        array $officialSource,
        int $expectedScore
    ): void {
        $evidenceDecision = $status === null ? [] : ['status' => $status];

        $calculator = $this->createCalculator();

        self::assertSame(
            $expectedScore,
            $calculator->calculateEvidenceMatchScore(
                $evidenceDecision,
                $verificationContextSafe,
                $formattedEvidenceSources,
                $officialSource
            )
        );
    }

    public static function provideEvidenceMatchScoreCases(): iterable
    {
        $oneFormattedSource = [
            [
                'title' => 'Example evidence source',
                'url' => 'https://example.com/evidence',
            ],
        ];

        yield 'supported without evidence and non-official source stays capped at 15 even when context is safe' => [
            'SUPPORTED',
            true,
            [],
            ['official' => false],
            15,
        ];

        yield 'supported without evidence and non-official source returns 15 when context is unsafe' => [
            'SUPPORTED',
            false,
            [],
            ['official' => false],
            15,
        ];

        yield 'partially supported without evidence and non-official source returns reduced score 10' => [
            'PARTIALLY_SUPPORTED',
            false,
            [],
            ['official' => false],
            10,
        ];

        yield 'contradicted without evidence and non-official source returns 5' => [
            'CONTRADICTED',
            false,
            [],
            ['official' => false],
            5,
        ];

        yield 'unrelated without evidence and non-official source returns 0' => [
            'UNRELATED',
            false,
            [],
            ['official' => false],
            0,
        ];

        yield 'unsupported without evidence and non-official source returns 0' => [
            'UNSUPPORTED',
            false,
            [],
            ['official' => false],
            0,
        ];

        yield 'unknown without evidence and non-official source returns 0' => [
            'UNKNOWN',
            false,
            [],
            ['official' => false],
            0,
        ];

        yield 'missing status without evidence and non-official source returns 0' => [
            null,
            false,
            [],
            ['official' => false],
            0,
        ];

        yield 'supported with usable evidence and safe context returns 42' => [
            'SUPPORTED',
            true,
            $oneFormattedSource,
            ['official' => false],
            42,
        ];

        yield 'supported with usable evidence but unsafe context returns 15' => [
            'SUPPORTED',
            false,
            $oneFormattedSource,
            ['official' => false],
            15,
        ];

        yield 'supported official source without formatted evidence and safe context returns 42' => [
            'SUPPORTED',
            true,
            [],
            ['official' => true],
            42,
        ];

        yield 'supported official source without formatted evidence but unsafe context returns 15' => [
            'SUPPORTED',
            false,
            [],
            ['official' => true],
            15,
        ];

        yield 'partially supported with usable evidence returns 28' => [
            'PARTIALLY_SUPPORTED',
            false,
            $oneFormattedSource,
            ['official' => false],
            28,
        ];

        yield 'unrelated with usable evidence returns 5' => [
            'UNRELATED',
            false,
            $oneFormattedSource,
            ['official' => false],
            5,
        ];

        yield 'unsupported with usable evidence returns 0' => [
            'UNSUPPORTED',
            false,
            $oneFormattedSource,
            ['official' => false],
            0,
        ];

        yield 'contradicted with usable evidence returns 5' => [
            'CONTRADICTED',
            false,
            $oneFormattedSource,
            ['official' => false],
            5,
        ];

        yield 'random status with usable evidence returns 0' => [
            'RANDOM_STATUS',
            false,
            $oneFormattedSource,
            ['official' => false],
            0,
        ];

        yield 'lowercase supported status is normalized and returns 42 with safe context and evidence' => [
            'supported',
            true,
            $oneFormattedSource,
            ['official' => false],
            42,
        ];
    }
        #[DataProvider('provideRiskSafetyScoreCases')]
    public function testCalculateRiskSafetyScoreReturnsCurrentScore(
        string $postText,
        int $expectedScore
    ): void {
        $calculator = $this->createCalculator();

        self::assertSame(
            $expectedScore,
            $calculator->calculateRiskSafetyScore($postText)
        );
    }

    public static function provideRiskSafetyScoreCases(): iterable
    {
        yield 'no risk signals returns maximum safety score 9' => [
            'Official announcement: the club confirmed the player transfer today.',
            9,
        ];

        yield 'one medium Arabic signal returns 7' => [
            'عاجل: اللاعب وقع رسميا مع النادي',
            7,
        ];

        yield 'two medium Arabic signals return 5' => [
            'عاجل وحصري: اللاعب وقع رسميا مع النادي',
            5,
        ];

        yield 'one high English signal returns 3' => [
            'Breaking: player signed today',
            3,
        ];

        yield 'two or more high English signals return 1' => [
            'Breaking exclusive leaked news about the player transfer',
            1,
        ];

        yield 'one high signal overrides multiple medium signals and returns 3' => [
            'Urgent rumor عاجل حصري about the player transfer',
            3,
        ];

        yield 'Arabic normalization matches feminine ending high signal فضيحة as فضيحه' => [
            'فضيحة كبيرة في النادي',
            3,
        ];

        yield 'Arabic normalization matches خاصة as خاصه in high signal مصادر خاصة' => [
            'مصادر خاصة تؤكد انتقال اللاعب',
            3,
        ];

        yield 'Arabic normalization matches القادمة as القادمه in medium signal' => [
            'في الساعات القادمة سيتم الإعلان عن الصفقة',
            7,
        ];

        yield 'uppercase English high signal is normalized and returns 3' => [
            'EXCLUSIVE news about the transfer',
            3,
        ];

        yield 'lowercase English medium signal returns 7' => [
            'reportedly the player signed today',
            7,
        ];
    }
        #[DataProvider('provideSourceAuthorityScoreCases')]
    public function testCalculateSourceAuthorityScoreReturnsCurrentScore(
        array $officialSource,
        array $evidenceItems,
        array $relevantIndexes,
        int $expectedScore
    ): void {
        $calculator = $this->createCalculator();

        self::assertSame(
            $expectedScore,
            $calculator->calculateSourceAuthorityScore(
                $officialSource,
                $evidenceItems,
                $relevantIndexes
            )
        );
    }

    public static function provideSourceAuthorityScoreCases(): iterable
    {
        yield 'official trusted category with confidence 85 returns 25' => [
            ['official' => true, 'confidence' => 85, 'category' => 'club'],
            [],
            [],
            25,
        ];

        yield 'official trusted category with confidence 84 returns 20' => [
            ['official' => true, 'confidence' => 84, 'category' => 'club'],
            [],
            [],
            20,
        ];

        yield 'official untrusted category returns 20 even with high confidence' => [
            ['official' => true, 'confidence' => 90, 'category' => 'media'],
            [],
            [],
            20,
        ];

        yield 'official source with missing confidence and category returns 20' => [
            ['official' => true],
            [],
            [],
            20,
        ];

        yield 'non-official source with no evidence returns 0' => [
            ['official' => false],
            [],
            [],
            0,
        ];

        yield 'non-official confidence 90 returns 23' => [
            ['official' => false],
            [['confidenceScore' => 90]],
            [],
            23,
        ];

        yield 'non-official confidence 89 returns 18' => [
            ['official' => false],
            [['confidenceScore' => 89]],
            [],
            18,
        ];

        yield 'non-official confidence 75 returns 18' => [
            ['official' => false],
            [['confidenceScore' => 75]],
            [],
            18,
        ];

        yield 'non-official confidence 74 returns 14' => [
            ['official' => false],
            [['confidenceScore' => 74]],
            [],
            14,
        ];

        yield 'non-official confidence 60 returns 14' => [
            ['official' => false],
            [['confidenceScore' => 60]],
            [],
            14,
        ];

        yield 'non-official confidence 59 returns 9' => [
            ['official' => false],
            [['confidenceScore' => 59]],
            [],
            9,
        ];

        yield 'non-official confidence 40 returns 9' => [
            ['official' => false],
            [['confidenceScore' => 40]],
            [],
            9,
        ];

        yield 'non-official confidence 39 returns 5' => [
            ['official' => false],
            [['confidenceScore' => 39]],
            [],
            5,
        ];

        yield 'non-official confidence 20 returns 5' => [
            ['official' => false],
            [['confidenceScore' => 20]],
            [],
            5,
        ];

        yield 'non-official confidence 19 returns 2' => [
            ['official' => false],
            [['confidenceScore' => 19]],
            [],
            2,
        ];

        yield 'sourceScore fallback returns 23 when confidenceScore is missing' => [
            ['official' => false],
            [['sourceScore' => 99]],
            [],
            23,
        ];

        yield 'confidenceScore has priority over sourceScore even when confidenceScore is zero' => [
            ['official' => false],
            [['confidenceScore' => 0, 'sourceScore' => 99]],
            [],
            2,
        ];

        yield 'relevant indexes filter evidence before calculating max confidence' => [
            ['official' => false],
            [
                ['confidenceScore' => 95],
                ['confidenceScore' => 20],
            ],
            [1],
            5,
        ];

        yield 'invalid relevant indexes return 0 because no relevant items are selected' => [
            ['official' => false],
            [['confidenceScore' => 95]],
            [99],
            0,
        ];
    }
        #[DataProvider('provideSourceIndependenceScoreCases')]
    public function testCalculateSourceIndependenceScoreReturnsCurrentScore(
        array $officialSource,
        array $evidenceItems,
        array $relevantIndexes,
        int $expectedScore
    ): void {
        $calculator = $this->createCalculator();

        self::assertSame(
            $expectedScore,
            $calculator->calculateSourceIndependenceScore(
                $officialSource,
                $evidenceItems,
                $relevantIndexes
            )
        );
    }

    public static function provideSourceIndependenceScoreCases(): iterable
    {
        yield 'official source with one evidence host returns 12' => [
            ['official' => true],
            [
                ['link' => 'https://official-source.tn/news', 'confidenceScore' => 10],
            ],
            [],
            12,
        ];

        yield 'official source with no evidence returns 10' => [
            ['official' => true],
            [],
            [],
            10,
        ];

        yield 'non-official 3 distinct hosts with confidence 75 returns 14' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 75],
                ['link' => 'https://source-b.com/news', 'confidenceScore' => 75],
                ['link' => 'https://source-c.com/news', 'confidenceScore' => 75],
            ],
            [],
            14,
        ];

        yield 'non-official 3 distinct hosts with confidence 74 returns 9' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 74],
                ['link' => 'https://source-b.com/news', 'confidenceScore' => 74],
                ['link' => 'https://source-c.com/news', 'confidenceScore' => 74],
            ],
            [],
            9,
        ];

        yield 'non-official 2 distinct hosts with confidence 75 returns 12' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 75],
                ['link' => 'https://source-b.com/news', 'confidenceScore' => 75],
            ],
            [],
            12,
        ];

        yield 'non-official 2 distinct hosts with confidence 50 returns 9' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 50],
                ['link' => 'https://source-b.com/news', 'confidenceScore' => 50],
            ],
            [],
            9,
        ];

        yield 'non-official 2 distinct hosts with confidence 49 returns 0' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 49],
                ['link' => 'https://source-b.com/news', 'confidenceScore' => 49],
            ],
            [],
            0,
        ];

        yield 'non-official 1 distinct host with confidence 75 returns 8' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 75],
            ],
            [],
            8,
        ];

        yield 'non-official 1 distinct host with confidence 74 returns 4' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 74],
            ],
            [],
            4,
        ];

        yield 'duplicate host counts once and returns single-source high-confidence score 8' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news-1', 'confidenceScore' => 95],
                ['link' => 'https://source-a.com/news-2', 'confidenceScore' => 95],
            ],
            [],
            8,
        ];

        yield 'www prefix and uppercase host normalization collapse to one host' => [
            ['official' => false],
            [
                ['link' => 'https://www.SOURCE-A.com/news-1', 'confidenceScore' => 95],
                ['link' => 'https://source-a.com/news-2', 'confidenceScore' => 95],
            ],
            [],
            8,
        ];

        yield 'source fallback is used when link is missing' => [
            ['official' => false],
            [
                ['source' => 'Mosaique FM', 'confidenceScore' => 95],
            ],
            [],
            8,
        ];

        yield 'source fallback trims and lowercases source name' => [
            ['official' => false],
            [
                ['source' => '  MOSAIQUE FM  ', 'confidenceScore' => 95],
            ],
            [],
            8,
        ];

        yield 'sourceScore fallback returns high single-source score when confidenceScore is missing' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'sourceScore' => 99],
            ],
            [],
            8,
        ];

        yield 'confidenceScore has priority over sourceScore even when confidenceScore is zero' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 0, 'sourceScore' => 99],
            ],
            [],
            4,
        ];

        yield 'relevant indexes filter to one selected item' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 95],
                ['link' => 'https://source-b.com/news', 'confidenceScore' => 95],
                ['link' => 'https://source-c.com/news', 'confidenceScore' => 95],
            ],
            [1],
            8,
        ];

        yield 'relevant indexes filter to two selected items' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 95],
                ['link' => 'https://source-b.com/news', 'confidenceScore' => 95],
                ['link' => 'https://source-c.com/news', 'confidenceScore' => 95],
            ],
            [0, 2],
            12,
        ];

        yield 'invalid relevant indexes return 0 because no relevant items are selected' => [
            ['official' => false],
            [
                ['link' => 'https://source-a.com/news', 'confidenceScore' => 95],
            ],
            [99],
            0,
        ];
    }

        private function createCalculator(): ScoreCalculator04B
    {
        return new ScoreCalculator04B(
            new EvidenceSourceMetrics04B()
        );
    }

    /**
     * Creates an object that satisfies a concrete service type without calling its constructor.
     *
     * This is safe here because calculateEvidenceMatchScore() does not call EvidenceSourceMetrics04B.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    
}
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;

use App\Service\EvidenceSourceMetrics04B;
use App\Service\ScoreCalculator04B;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

    private function createCalculator(): ScoreCalculator04B
    {
        return new ScoreCalculator04B(
            $this->inertService(EvidenceSourceMetrics04B::class)
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
    private function inertService(string $className): object
    {
        return (new ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
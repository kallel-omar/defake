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
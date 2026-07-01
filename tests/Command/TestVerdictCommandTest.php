<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TestVerdictCommand;
use App\Service\PostAnalysisService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TestVerdictCommandTest extends TestCase
{
    public function testPassesNormalizedContextToPostAnalysisService(): void
    {
        $postAnalysisService = new class extends PostAnalysisService {
            public array $arguments = [];

            public function __construct()
            {
            }

            public function analyze(string $url, string $postText, array $sourceContext = [], array $analysisContext = []): array
            {
                $this->arguments = func_get_args();

                return [
                    'score' => 0,
                    'verdict' => 'NOT_VERIFIABLE',
                    'mainClaim' => null,
                    'evidenceSources' => [],
                    'explanation' => 'Test result.',
                ];
            }
        };

        $tester = new CommandTester(new TestVerdictCommand($postAnalysisService));
        $tester->execute([
            'text' => 'Mo2men Rahmani in css for 2 years',
            '--country' => 'tn',
            '--topic' => 'Sports',
            '--post-url' => 'cli://manual-test',
        ]);

        self::assertSame('cli://manual-test', $postAnalysisService->arguments[0] ?? null);
        self::assertSame('Mo2men Rahmani in css for 2 years', $postAnalysisService->arguments[1] ?? null);
        self::assertSame('cli://manual-test', $postAnalysisService->arguments[2]['postUrl'] ?? null);
        self::assertSame([
            'country' => 'TN',
            'topic' => 'sports',
        ], $postAnalysisService->arguments[3] ?? null);
    }

    public function testEmptyContextFlagsPassEmptyAnalysisContext(): void
    {
        $postAnalysisService = new class extends PostAnalysisService {
            public array $arguments = [];

            public function __construct()
            {
            }

            public function analyze(string $url, string $postText, array $sourceContext = [], array $analysisContext = []): array
            {
                $this->arguments = func_get_args();

                return [
                    'score' => 0,
                    'verdict' => 'NOT_VERIFIABLE',
                    'mainClaim' => null,
                    'evidenceSources' => [],
                    'explanation' => 'Test result.',
                ];
            }
        };

        $tester = new CommandTester(new TestVerdictCommand($postAnalysisService));
        $tester->execute([
            'text' => 'OpenAI launched a new model today',
            '--country' => '',
            '--topic' => '',
        ]);

        self::assertSame([], $postAnalysisService->arguments[3] ?? null);
    }
}

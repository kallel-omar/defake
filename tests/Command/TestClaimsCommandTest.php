<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TestClaimsCommand;
use App\Service\ClaimExtractionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TestClaimsCommandTest extends TestCase
{
    public function testDoesNotDisplayNoVerifiableClaimMarkerAsExtractedClaim(): void
    {
        $claimExtractionService = $this->createMock(ClaimExtractionService::class);
        $claimExtractionService
            ->expects(self::once())
            ->method('extract')
            ->with('angry post without a checkable claim')
            ->willReturn(['NO_VERIFIABLE_CLAIM']);

        $tester = new CommandTester(new TestClaimsCommand($claimExtractionService));
        $tester->execute([
            'text' => 'angry post without a checkable claim',
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('No claims extracted.', $display);
        self::assertStringNotContainsString('1. NO_VERIFIABLE_CLAIM', $display);
    }

    public function testDisplaysRealExtractedClaim(): void
    {
        $claim = 'النادي الافريقي خسر نقطتين أمام سليمان بعد احتساب ضربة جزاء غير صحيحة لسليمان';
        $claimExtractionService = $this->createMock(ClaimExtractionService::class);
        $claimExtractionService
            ->expects(self::once())
            ->method('extract')
            ->with($claim)
            ->willReturn([$claim]);

        $tester = new CommandTester(new TestClaimsCommand($claimExtractionService));
        $tester->execute([
            'text' => $claim,
        ]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Extracted Claims', $display);
        self::assertStringContainsString('1. ' . $claim, $display);
        self::assertStringNotContainsString('NO_VERIFIABLE_CLAIM', $display);
    }
}

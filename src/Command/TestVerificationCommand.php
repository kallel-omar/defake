<?php

namespace App\Command;

use App\Service\ClaimVerificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-verification',
    description: 'Test claim verification',
)]
class TestVerificationCommand extends Command
{
    public function __construct(
        private readonly ClaimVerificationService $claimVerificationService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $claim = 'Inflation reached 7.2% in May';

        $evidence = '
Reuters reported that inflation reached 7.2% in May.

BBC also reported inflation at 7.2%.

The central bank confirmed the figure.
';

        $result = $this->claimVerificationService->verify(
            $claim,
            $evidence
        );

        dump($result);

        return Command::SUCCESS;
    }
}
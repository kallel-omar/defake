<?php

namespace App\Command;

use App\Service\ClaimExtractionService;
use App\Service\ClaimVerificationService;
use App\Service\InternetEvidenceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-pipeline',
    description: 'Test the full DeFake pipeline',
)]
class TestPipelineCommand extends Command
{
    public function __construct(
        private readonly ClaimExtractionService $claimExtractionService,
        private readonly InternetEvidenceService $internetEvidenceService,
        private readonly ClaimVerificationService $claimVerificationService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $postText = '
Reuters reported that inflation reached 7.2% in May while the central bank kept interest rates unchanged.
';

        $claims = $this->claimExtractionService->extract($postText);

        $io->title('Detected Claims');

        foreach ($claims as $claim) {

    $io->section($claim);

    $evidenceQuery = $postText . "\n\nClaim to verify:\n" . $claim;

$evidence = $this->internetEvidenceService->search($evidenceQuery);

$verification = $this->claimVerificationService->verify(
    $claim,
    $evidence,
    $postText
);

    $io->writeln('Verdict: ' . $verification['verdict']);
    $io->writeln('Score: ' . $verification['score']);
    $io->writeln('Context match: ' . (($verification['contextMatch'] ?? false) ? 'yes' : 'no'));

if (!empty($verification['contextReason'])) {
    $io->writeln('Context reason: ' . $verification['contextReason']);
}
    $io->writeln('Explanation: ' . $verification['explanation']);
    $io->newLine();
}

        return Command::SUCCESS;
    }
}
<?php

namespace App\Command;

use App\Service\ClaimExtractionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-claims',
    description: 'Test claim extraction from a text',
)]
class TestClaimsCommand extends Command
{
    public function __construct(
        private readonly ClaimExtractionService $claimExtractionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'text',
            InputArgument::REQUIRED,
            'The post text to analyze'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $text = (string) $input->getArgument('text');

        $claims = $this->claimExtractionService->extract($text);

        if (empty($claims)) {
            $io->warning('No claims extracted.');
            return Command::SUCCESS;
        }

        $io->title('Extracted Claims');

        foreach ($claims as $index => $claim) {
            $io->writeln(($index + 1) . '. ' . $claim);
        }

        return Command::SUCCESS;
    }
}
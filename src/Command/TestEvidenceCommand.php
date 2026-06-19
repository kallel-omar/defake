<?php

namespace App\Command;

use App\Service\InternetEvidenceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-evidence',
    description: 'Test internet evidence search',
)]
class TestEvidenceCommand extends Command
{
    public function __construct(
        private readonly InternetEvidenceService $internetEvidenceService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $claim = 'Inflation reached 7.2% in May';

        $evidence = $this->internetEvidenceService->search($claim);

        $io->writeln($evidence);

        return Command::SUCCESS;
    }
}
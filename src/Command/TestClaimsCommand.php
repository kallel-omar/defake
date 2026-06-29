<?php

namespace App\Command;

use App\Service\ClaimExtractionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
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
    $this
        ->addArgument('text', InputArgument::REQUIRED, 'Text to extract claims from')
        ->addOption('source-type', null, InputOption::VALUE_REQUIRED, 'Source type context', 'manual_entry')
        ->addOption('page-name', null, InputOption::VALUE_REQUIRED, 'Facebook/page source name')
        ->addOption('user-name', null, InputOption::VALUE_REQUIRED, 'Facebook/user source name');
}
    

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $text = (string) $input->getArgument('text');

        $sourceContext = [
    'source_type' => (string) $input->getOption('source-type'),
];

$pageName = $input->getOption('page-name');
if (is_string($pageName) && trim($pageName) !== '') {
    $sourceContext['pageName'] = trim($pageName);
}

$userName = $input->getOption('user-name');
if (is_string($userName) && trim($userName) !== '') {
    $sourceContext['userName'] = trim($userName);
}

$claims = $this->claimExtractionService->extract($text, $sourceContext);

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
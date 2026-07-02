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
    private const NO_VERIFIABLE_CLAIM = 'NO_VERIFIABLE_CLAIM';

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

        $claims = $this->normalizeClaimsForDisplay($this->claimExtractionService->extract($text));

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

    private function normalizeClaimsForDisplay(array $claims): array
    {
        return array_values(array_filter(array_map(
            static function (mixed $claim): ?string {
                if (!is_scalar($claim)) {
                    return null;
                }

                $claim = trim((string) $claim);

                if ($claim === '' || mb_strtoupper($claim) === self::NO_VERIFIABLE_CLAIM) {
                    return null;
                }

                return $claim;
            },
            $claims
        )));
    }
}

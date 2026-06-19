<?php

namespace App\Command;

use App\Service\PostVerdictService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test-verdict',
    description: 'Test PostVerdictService',
)]
class TestVerdictCommand extends Command
{
    public function __construct(
        private readonly PostVerdictService $postVerdictService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $claimResults = [
            [
                'verdict' => 'SUPPORTED',
                'score' => 95,
            ],
            [
                'verdict' => 'INSUFFICIENT_EVIDENCE',
                'score' => 30,
            ],
            [
                'verdict' => 'CONTRADICTED',
                'score' => 10,
            ],
        ];

        dump(
            $this->postVerdictService->calculate($claimResults)
        );

        return Command::SUCCESS;
    }
}
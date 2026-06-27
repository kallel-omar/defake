<?php

namespace App\Command;

use App\Service\GroqAiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-groq',
    description: 'Test Groq API'
)]
class TestGroqCommand extends Command
{
    public function __construct(
        private readonly GroqAiService $groqAiService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $response = $this->groqAiService->ask([
            [
                'role' => 'system',
                'content' => 'Return only valid JSON. No markdown.',
            ],
            [
                'role' => 'user',
                'content' => 'Return exactly this JSON: {"ok":true,"provider":"groq"}',
            ],
        ], 100);

        $io->writeln($response ?? 'NULL');

        $data = json_decode((string) $response, true);

        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            $io->error('Groq did not return valid JSON.');
            return Command::FAILURE;
        }

        $io->success('Groq test finished successfully.');

        return Command::SUCCESS;
    }
}
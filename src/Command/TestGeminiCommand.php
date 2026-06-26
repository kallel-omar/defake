<?php

namespace App\Command;

use App\Service\GeminiAiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-gemini',
    description: 'Test Gemini API',
)]
class TestGeminiCommand extends Command
{
    public function __construct(
        private readonly GeminiAiService $geminiAiService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $response = $this->geminiAiService->analyzeEvidence(
            'The Earth is flat',
            [
                [
                    'title' => 'NASA',
                    'snippet' => 'The Earth is an oblate spheroid.',
                    'link' => 'https://www.nasa.gov/'
                ]
            ]
        );

        dump($response);

        $io->success('Gemini test finished.');

        return Command::SUCCESS;
    }
}
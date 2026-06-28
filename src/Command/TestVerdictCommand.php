<?php

namespace App\Command;

use App\Service\PostAnalysisService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-verdict',
    description: 'Test the real DeFake PostAnalysisService scoring pipeline',
)]
class TestVerdictCommand extends Command
{
    public function __construct(
        private readonly PostAnalysisService $postAnalysisService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'text',
                InputArgument::OPTIONAL,
                'Facebook post text to analyze'
            )
            ->addOption(
                'page-name',
                null,
                InputOption::VALUE_OPTIONAL,
                'Facebook page name'
            )
            ->addOption(
                'user-name',
                null,
                InputOption::VALUE_OPTIONAL,
                'Facebook user/page username'
            )
            ->addOption(
                'user-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Facebook page/user ID'
            )
            ->addOption(
                'post-url',
                null,
                InputOption::VALUE_OPTIONAL,
                'Facebook post URL'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $postText = trim((string) ($input->getArgument('text') ?? ''));

        if ($postText === '') {
            $postText = 'صفقة كبيرة قادمة للترجي ومفاجأة مدوية في الساعات القادمة';
        }

        $sourceContext = [
            'pageName' => trim((string) ($input->getOption('page-name') ?? 'CLI Test Page')),
            'userName' => trim((string) ($input->getOption('user-name') ?? 'CLI Test')),
            'userId' => trim((string) ($input->getOption('user-id') ?? 'cli-test')),
            'postUrl' => trim((string) ($input->getOption('post-url') ?? 'cli://test-post')),
        ];

        $result = $this->postAnalysisService->analyze(
            $sourceContext['postUrl'],
            $postText,
            $sourceContext
        );

        $io->section('Input Post');
        $io->writeln($postText);

        $io->section('Source Context');
        $io->writeln(json_encode($sourceContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $io->section('Analysis Result');
        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $io->success('Verdict test finished successfully.');

        return Command::SUCCESS;
    }
}
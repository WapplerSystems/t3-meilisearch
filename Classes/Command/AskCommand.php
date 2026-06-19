<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\RagService;

/**
 * Ad-hoc CLI for the RAG pipeline. Useful for verifying provider connectivity
 * and prompt tuning without round-tripping the frontend.
 */
#[AsCommand(
    name: 'ws_meilisearch:ask',
    description: 'Run a RAG question against a site and print the answer + cited sources.',
)]
final class AskCommand extends Command
{
    public function __construct(
        private readonly RagService $ragService,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('question', InputArgument::REQUIRED, 'The question to ask.')
            ->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (defaults to the first configured site).')
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Restrict retrieval to a specific site language id (e.g. 0 for default). '
                . 'Without this, RAG retrieval scans every language — files indexed in N '
                . 'languages then crowd the top-K context with N copies of the same record. '
                . 'Default: 0 (the site default language).',
                '0',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $question = (string)$input->getArgument('question');
        $siteId = $input->getArgument('site');

        $site = $siteId !== null
            ? $this->siteFinder->getSiteByIdentifier($siteId)
            : ($this->siteFinder->getAllSites()[array_key_first($this->siteFinder->getAllSites())] ?? null);
        if ($site === null) {
            $io->error('No site available.');
            return Command::FAILURE;
        }

        $io->section('Site: ' . $site->getIdentifier());
        $io->writeln('<comment>Q:</comment> ' . $question);

        $options = [];
        $languageOption = $input->getOption('language');
        if ($languageOption !== '' && $languageOption !== null) {
            $options['filters'] = ['language' => [(int)$languageOption]];
            // Pin the LLM answer to the same language as the retrieval
            // filter so it doesn't drift to English on multi-language
            // context. Matches the FE controller behaviour.
            $options['language'] = (int)$languageOption;
        }

        $answer = $this->ragService->ask($site, $question, $options);
        if ($answer->status !== 'ok') {
            $io->warning('Status: ' . $answer->status . ($answer->error !== null ? ' — ' . $answer->error : ''));
            return $answer->status === 'failed' ? Command::FAILURE : Command::SUCCESS;
        }

        $io->writeln('');
        $io->writeln('<info>Answer:</info>');
        $io->writeln($answer->answer);
        $io->writeln('');

        if ($answer->sources !== []) {
            $io->writeln('<info>Sources fed into context (cited ' . count($answer->citedIds) . ' of ' . count($answer->sources) . '):</info>');
            foreach ($answer->sources as $hit) {
                $id = (string)($hit['id'] ?? '?');
                $marker = in_array($id, $answer->citedIds, true) ? '✓' : ' ';
                $io->writeln(sprintf('  %s [%s] %s', $marker, $id, (string)($hit['title'] ?? '')));
            }
        }
        return Command::SUCCESS;
    }
}

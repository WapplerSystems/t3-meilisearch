<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WapplerSystems\Meilisearch\Service\HelpDocImporter;

/**
 * CLI shell over {@see HelpDocImporter}. All parsing / FAL / purge
 * logic lives in the service so the BE module can drive imports through
 * the same code path; the CLI's value-add is the SymfonyStyle progress
 * bar and the operator-facing option parsing.
 */
#[AsCommand(
    name: 'ws_meilisearch:import-help-docs',
    description: 'Import DITA-OT XHTML help topics into tx_wsmeilisearch_helpdoc + Meilisearch.',
)]
final class ImportHelpDocsCommand extends Command
{
    public function __construct(
        private readonly HelpDocImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the DITA-OT root folder (containing index.html + the language subdir). Relative to project root or absolute.')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'TYPO3 sys_language_uid for the imported records.', '0')
            ->addOption('langDir', null, InputOption::VALUE_REQUIRED, 'Subdirectory name under --path that contains the topics/ folder.', 'de')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Storage pid for the new records (default 0 = site root).', '0')
            ->addOption('no-purge', null, InputOption::VALUE_NONE, 'Skip the truncate step. Default is purge-and-rebuild — re-runs are idempotent because the importer drops everything for the given language first.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only import the first N topics. Useful for quick smoke tests.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawPath = (string)$input->getOption('path');
        if ($rawPath === '') {
            $io->error('--path is required.');
            return Command::FAILURE;
        }
        $languageId = (int)$input->getOption('language');
        $pid = (int)$input->getOption('pid');
        $limit = max(0, (int)$input->getOption('limit'));
        $purge = !$input->getOption('no-purge');

        $io->section(sprintf(
            'Import from %s (language=%d, pid=%d, purge=%s)',
            $this->importer->resolvePath($rawPath),
            $languageId,
            $pid,
            $purge ? 'yes' : 'no',
        ));

        $progressBar = null;
        $onProgress = function (int $current, int $total, string $identifier) use (&$progressBar, $io): void {
            if ($progressBar === null) {
                $io->writeln(sprintf('Importing %d topic(s)…', $total));
                $progressBar = $io->createProgressBar($total);
                $progressBar->start();
            }
            $progressBar->setProgress($current);
        };

        try {
            $result = $this->importer->import(
                path: (string)$input->getOption('path'),
                langDir: (string)$input->getOption('langDir'),
                languageId: $languageId,
                pid: $pid,
                purge: $purge,
                limit: $limit,
                onProgress: $onProgress,
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($progressBar instanceof ProgressBar) {
            $progressBar->finish();
            $io->newLine(2);
        }
        $io->success(sprintf(
            'Imported %d topic(s) (%d skipped, %d media files attached). Run `ws_meilisearch:reindex` to push them to Meilisearch.',
            $result['imported'],
            $result['skipped'],
            $result['mediaCopied'],
        ));
        return Command::SUCCESS;
    }
}
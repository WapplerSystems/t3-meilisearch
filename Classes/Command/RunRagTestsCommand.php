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
use WapplerSystems\Meilisearch\Service\RagTest\RagTestResult;
use WapplerSystems\Meilisearch\Service\RagTest\RagTestRunner;

/**
 * Walk every enabled tx_wsmeilisearch_ragtest row, fire the question
 * at the configured RAG provider, score the answer against the
 * expected one via embedding cosine similarity, persist the result.
 *
 * Suitable for cron — the per-row threshold and pass/fail/error
 * taxonomy give a clear "regression vs infrastructure problem"
 * separation in the exit code: any FAIL yields exit 1, ERRORs alone
 * yield exit 2 (worth attention but not necessarily a quality
 * regression), all PASS yields exit 0.
 */
#[AsCommand(
    name: 'ws_meilisearch:run-rag-tests',
    description: 'Run the RAG regression tests stored in tx_wsmeilisearch_ragtest.',
)]
final class RunRagTestsCommand extends Command
{
    public function __construct(
        private readonly RagTestRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'site',
            InputArgument::OPTIONAL,
            'Restrict to tests with this site_identifier. Omit to run every enabled test.',
        );
        $this->addOption(
            'show-answers',
            null,
            InputOption::VALUE_NONE,
            'Print the actual answer for each test (verbose, useful when debugging a failing row).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteFilter = $input->getArgument('site') ?: null;
        $verbose = (bool)$input->getOption('show-answers');

        $io->title('RAG regression tests' . ($siteFilter ? " (site={$siteFilter})" : ''));

        $results = $this->runner->runAll($siteFilter);
        if ($results === []) {
            $io->warning('No tests found' . ($siteFilter ? " for site '{$siteFilter}'." : '.'));
            return Command::SUCCESS;
        }

        $passes = 0;
        $fails = 0;
        $errors = 0;
        $rows = [];
        foreach ($results as $entry) {
            /** @var RagTestResult $r */
            $r = $entry['result'];
            $statusLabel = match ($r->status) {
                RagTestResult::PASS => '<fg=green>PASS</>',
                RagTestResult::FAIL => '<fg=red>FAIL</>',
                default             => '<fg=yellow>ERROR</>',
            };
            $passes += $r->status === RagTestResult::PASS ? 1 : 0;
            $fails += $r->status === RagTestResult::FAIL ? 1 : 0;
            $errors += $r->status === RagTestResult::ERROR ? 1 : 0;

            $rows[] = [
                $entry['uid'],
                mb_substr($entry['title'], 0, 48),
                $statusLabel,
                $r->score !== null ? sprintf('%.3f', $r->score) : '—',
                $r->error !== '' ? mb_substr($r->error, 0, 60) : '',
            ];
            if ($verbose && $r->actualAnswer !== '') {
                $io->writeln(sprintf('  <info>#%d</info> actual: %s', $entry['uid'], mb_substr($r->actualAnswer, 0, 200)));
            }
        }
        $io->table(['uid', 'title', 'status', 'score', 'error'], $rows);

        $io->writeln(sprintf(
            '<info>Summary:</info> %d passed, <error>%d failed</error>, <comment>%d errored</comment> (of %d tests)',
            $passes,
            $fails,
            $errors,
            count($results),
        ));

        if ($fails > 0) {
            return Command::FAILURE;
        }
        if ($errors > 0) {
            return 2; // distinct from FAILURE so cron can tell quality regression from infrastructure issue
        }
        return Command::SUCCESS;
    }
}

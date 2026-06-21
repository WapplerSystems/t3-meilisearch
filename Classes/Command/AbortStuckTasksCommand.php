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
use WapplerSystems\Meilisearch\Service\Watchdog\StuckTaskWatchdog;

/**
 * Cron-friendly watchdog: detect + cancel stuck Meilisearch tasks
 * and email the configured recipient.
 *
 * Typical schedule: every 15-30 minutes via system cron or the TYPO3
 * Scheduler. With `meilisearch.watchdog.stuckThresholdMinutes=30` (the
 * default), an embedder hang gets cleared within an hour without
 * operator intervention.
 *
 * Exit codes:
 *   0 — no stuck tasks (or --dry-run)
 *   1 — stuck tasks found + cancelled (lets cron monitors latch)
 */
#[AsCommand(
    name: 'ws_meilisearch:abort-stuck-tasks',
    description: 'Cancel Meilisearch tasks stuck in processing/enqueued past the configured threshold and email the operator.',
)]
final class AbortStuckTasksCommand extends Command
{
    public function __construct(
        private readonly StuckTaskWatchdog $watchdog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'site',
            InputArgument::OPTIONAL,
            'Restrict to one site identifier. Omit to check every site.',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List stuck tasks without cancelling them or sending email.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteFilter = $input->getArgument('site') ?: null;
        $dryRun = (bool)$input->getOption('dry-run');

        $io->title('Meilisearch stuck-task watchdog' . ($siteFilter ? " (site={$siteFilter})" : ''));

        $reports = $this->watchdog->run($siteFilter, $dryRun);
        if ($reports === []) {
            $io->warning('No Meilisearch-configured sites available.');
            return Command::SUCCESS;
        }

        $totalStuck = 0;
        $rows = [];
        foreach ($reports as $report) {
            $totalStuck += $report->stuckCount;
            $rows[] = [
                $report->site,
                $report->stuckCount,
                $report->embedderReset ? 'yes' : 'no',
                $report->dryRun
                    ? 'dry-run'
                    : ($report->recipient === ''
                        ? '<fg=yellow>no recipient</>'
                        : ($report->emailSent ? 'sent → ' . $report->recipient : 'fail → ' . $report->recipient)),
            ];
        }
        $io->table(['site', 'stuck', 'embedder reset', 'email'], $rows);

        if ($totalStuck === 0) {
            $io->success('No stuck tasks found.');
            return Command::SUCCESS;
        }
        // List the individual task uids in verbose mode for easier triage.
        if ($io->isVerbose()) {
            foreach ($reports as $report) {
                foreach ($report->stuckTasks as $task) {
                    $io->writeln(sprintf(
                        '<comment>%s</> task #%d (%s) %s — enqueued %s, age %d min',
                        $report->site,
                        $task['uid'],
                        $task['type'],
                        $task['status'],
                        $task['enqueuedAt'],
                        $task['ageMinutes'],
                    ));
                }
            }
        }
        $io->warning(sprintf(
            '%d stuck task(s) %s — see email or rerun with -v for details.',
            $totalStuck,
            $dryRun ? 'identified (dry-run; not cancelled)' : 'cancelled',
        ));
        return Command::FAILURE;
    }
}

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
use WapplerSystems\Meilisearch\Service\Quota\QuotaCheckRunner;

/**
 * Checks the commercial AI providers configured on each site (Infomaniak,
 * OpenAI, Anthropic) against their usage caps and emails a warning when
 * any provider exceeds meilisearch.quota.threshold (default 80%).
 *
 * Designed for cron: idempotent, only mails when actually over threshold,
 * exit code 1 if any provider is over (lets cron monitors latch). Add
 * --dry-run to print the table without sending the email — useful for
 * verifying the threshold + recipient config without spamming inboxes.
 */
#[AsCommand(
    name: 'ws_meilisearch:check-quotas',
    description: 'Check commercial AI provider quotas + email when over threshold.',
)]
final class CheckQuotasCommand extends Command
{
    public function __construct(
        private readonly QuotaCheckRunner $runner,
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
            'Print results without sending warning emails.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteFilter = $input->getArgument('site') ?: null;
        $sendEmail = !$input->getOption('dry-run');

        $io->title('Commercial AI provider quotas' . ($siteFilter ? " (site={$siteFilter})" : ''));

        $results = $this->runner->check($siteFilter, $sendEmail);
        if ($results === []) {
            $io->warning('No commercial providers configured on any site.');
            return Command::SUCCESS;
        }

        $rows = [];
        $overCount = 0;
        $errCount = 0;
        foreach ($results as $entry) {
            $status = $entry['status'];
            $label = $status->isError()
                ? '<fg=yellow>ERROR</>'
                : ($entry['overThreshold'] ? '<fg=red>OVER</>' : '<fg=green>OK</>');
            if ($status->isError()) {
                $errCount++;
            } elseif ($entry['overThreshold']) {
                $overCount++;
            }
            $rows[] = [
                $entry['site'],
                $status->provider,
                $label,
                $status->isError() ? '—' : sprintf('%.1f%%', $status->usedPercent),
                $status->isError() ? '—' : sprintf('%s / %s', number_format($status->used), number_format($status->limit)),
                $entry['threshold'] . '%',
                $entry['mailed'] ? 'sent' : ($sendEmail && $entry['overThreshold'] ? 'fail' : ''),
                $status->error ?? '',
            ];
        }
        $io->table(['site', 'provider', 'state', 'used', 'tokens', 'threshold', 'email', 'error'], $rows);

        $io->writeln(sprintf(
            '<info>Summary:</info> %d over threshold, %d errored, %d total',
            $overCount,
            $errCount,
            count($results),
        ));

        return $overCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

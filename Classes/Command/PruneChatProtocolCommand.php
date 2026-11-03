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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRepository;

/**
 * Deletes chat protocol turns older than the per-site retention period, and
 * search analytics rows older than meilisearch.analytics.retentionDays.
 *
 * Designed for the TYPO3 scheduler (“Execute console commands” task, daily).
 * The protocol stores the visitors’ full wording, so retention is a
 * practical data-minimisation control for a personal-data-heavy table. The
 * analytics log holds the wording too (search terms and chat questions, cut
 * at 255 characters); its retention setting existed, but nothing applied it,
 * and the rows stayed for good.
 */
#[AsCommand(
    name: 'ws_meilisearch:rag:protocol:prune',
    description: 'Delete chat protocol turns older than meilisearch.rag.protocol.retentionDays and search analytics rows older than meilisearch.analytics.retentionDays.',
)]
final class PruneChatProtocolCommand extends Command
{
    public function __construct(
        private readonly ChatProtocolRepository $repository,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'site',
            InputArgument::OPTIONAL,
            'Restrict pruning to one site identifier. Omit to process every site.',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Print the cutoff per site without deleting anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteArgument = $input->getArgument('site');
        $siteFilter = $siteArgument !== null && $siteArgument !== '' ? (string)$siteArgument : null;
        $dryRun = (bool)$input->getOption('dry-run');

        if ($siteFilter !== null) {
            try {
                $sites = [$this->siteFinder->getSiteByIdentifier($siteFilter)];
            } catch (SiteNotFoundException) {
                $io->error('Unknown site identifier: ' . $siteFilter);
                return Command::FAILURE;
            }
        } else {
            $sites = $this->siteFinder->getAllSites();
            if ($sites === []) {
                $io->warning('No sites found.');
                return Command::SUCCESS;
            }
        }

        $io->title('Prune chat protocol entries');

        $rows = [];
        $deletedTotal = 0;

        foreach ($sites as $site) {
            $identifier = $site->getIdentifier();
            $days = (int)$site->getSettings()->get('meilisearch.rag.protocol.retentionDays', 90);
            $rows = array_merge($rows, $this->pruneAnalytics($identifier, (int)$site->getSettings()->get('meilisearch.analytics.retentionDays', 90), $dryRun, $deletedTotal));

            if ($days <= 0) {
                $rows[] = [$identifier, $days, '—', 'keep forever'];
                continue;
            }

            $cutoff = time() - $days * 86400;

            if ($dryRun) {
                $rows[] = [$identifier, $days, date('Y-m-d H:i', $cutoff), 'dry run, nothing deleted'];
            } else {
                $deleted = $this->repository->deleteOlderThan($identifier, $cutoff);
                $deletedTotal += $deleted;
                $rows[] = [$identifier, $days, date('Y-m-d H:i', $cutoff), (string)$deleted];
            }
        }

        $io->table(['Site', 'Retention days', 'Cutoff', 'Deleted'], $rows);

        if ($dryRun) {
            $io->note('Dry run, nothing deleted.');
        } else {
            $io->success(sprintf('%d row(s) deleted.', $deletedTotal));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<list<string|int>>
     */
    private function pruneAnalytics(string $identifier, int $days, bool $dryRun, int &$deletedTotal): array
    {
        $label = $identifier . ' (analytics)';
        if ($days <= 0) {
            return [[$label, $days, '—', 'keep forever']];
        }
        $cutoff = time() - $days * 86400;
        if ($dryRun) {
            return [[$label, $days, date('Y-m-d H:i', $cutoff), 'dry run, nothing deleted']];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_wsmeilisearch_search_log');
        $deleted = (int)$qb->delete('tx_wsmeilisearch_search_log')
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($identifier)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($cutoff, Connection::PARAM_INT)),
            )
            ->executeStatement();
        $deletedTotal += $deleted;

        return [[$label, $days, date('Y-m-d H:i', $cutoff), (string)$deleted]];
    }
}

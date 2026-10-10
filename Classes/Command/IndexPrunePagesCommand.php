<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Lochmueller\Index\Configuration\Configuration;
use Lochmueller\Index\Configuration\ConfigurationLoader;
use Lochmueller\Index\Indexing\ActiveIndexing;
use Meilisearch\Contracts\DocumentsQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\PageIndexEligibility;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Remove page documents whose page may no longer be found: deleted, hidden,
 * outside its start/end time, "not searchable", inside an excluded page tree,
 * or a translation that is gone.
 *
 * Backend actions are synced at once (PageIndexSync); this command catches
 * what changes without one: an end time that passes, and anything a failed
 * sync left behind. With --started-since it also queues pages whose start
 * time was reached in that window — they become visible without anybody
 * saving them, so EXT:index never hears of them. Meant to run every 15
 * minutes as a scheduler task (`--started-since=1200`, overlapping runs are
 * harmless); with --dry-run it only lists what it would do.
 */
#[AsCommand(
    name: 'ws_meilisearch:index-prune-pages',
    description: 'Remove page documents of hidden, deleted, unsearchable or excluded pages from the index.'
)]
final class IndexPrunePagesCommand extends Command
{
    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        private readonly SiteFinder $siteFinder,
        private readonly PageIndexEligibility $eligibility,
        private readonly ConnectionPool $connectionPool,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly ActiveIndexing $activeIndexing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: every site with meilisearch.url)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the documents that would be removed')
            ->addOption('started-since', null, InputOption::VALUE_REQUIRED, 'Also queue pages whose start time was reached within the last N seconds', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $siteId = $input->getArgument('site');

        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($siteId !== null ? $site->getIdentifier() === $siteId : (string)$site->getSettings()->get('meilisearch.url', '') !== '') {
                $sites[] = $site;
            }
        }
        if ($sites === []) {
            $io->error('No matching site found.');
            return Command::FAILURE;
        }

        $result = Command::SUCCESS;
        foreach ($sites as $site) {
            if (!$this->pruneSite($site, $dryRun, $io)) {
                $result = Command::FAILURE;
            }
        }
        $startedSince = max(0, (int)$input->getOption('started-since'));
        if ($startedSince > 0) {
            $this->queueStartedPages($sites, $startedSince, $dryRun, $io);
        }

        return $result;
    }

    /**
     * @param list<Site> $sites
     */
    private function queueStartedPages(array $sites, int $seconds, bool $dryRun, SymfonyStyle $io): void
    {
        $now = time();
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $uids = $qb->select('uid')
            ->from('pages')
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('sys_language_uid', 0),
                $qb->expr()->gt('starttime', $now - $seconds),
                $qb->expr()->lte('starttime', $now),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $queued = 0;
        $this->eligibility->forgetCache();
        foreach (array_map('intval', $uids) as $uid) {
            $site = null;
            foreach ($sites as $candidate) {
                if ($candidate->getRootPageId() === $uid || in_array($candidate->getRootPageId(), $this->rootlineIds($uid), true)) {
                    $site = $candidate;
                    break;
                }
            }
            if ($site === null || $this->eligibility->ineligibleReason($uid, 0, $site) !== null) {
                continue;
            }
            $configuration = $this->configurationLoader->loadByPageTraversing($uid);
            if (!$configuration instanceof Configuration) {
                continue;
            }
            $io->writeln(sprintf('  start time reached: page %d', $uid), OutputInterface::VERBOSITY_VERBOSE);
            if (!$dryRun) {
                $this->activeIndexing->fillQueue($configuration->modifyForPartialIndexing($uid), true);
            }
            $queued++;
        }
        $io->writeln(sprintf('Pages whose start time was reached in the last %d s: %d %s', $seconds, $queued, $dryRun ? 'would be queued' : 'queued'));
    }

    /**
     * @return list<int>
     */
    private function rootlineIds(int $uid): array
    {
        $ids = [];
        for ($depth = 0; $uid > 0 && $depth < 64; $depth++) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();
            $uid = (int)$qb->select('pid')->from('pages')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
                ->executeQuery()->fetchOne();
            if ($uid > 0) {
                $ids[] = $uid;
            }
        }

        return $ids;
    }

    private function pruneSite(Site $site, bool $dryRun, SymfonyStyle $io): bool
    {
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            $io->error('Site "' . $site->getIdentifier() . '" has no meilisearch.url configured.');
            return false;
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $this->eligibility->forgetCache();

        $remove = [];      // id => reason
        $checked = 0;
        $offset = 0;
        do {
            $page = $client->index($indexName)->getDocuments(
                (new DocumentsQuery())
                    ->setFields(['id', 'uid', 'language', 'title', 'uri'])
                    ->setFilter(['type = "page"'])
                    ->setOffset($offset)
                    ->setLimit(1000),
            );
            $rows = $page->getResults();
            foreach ($rows as $row) {
                $checked++;
                $id = (string)($row['id'] ?? '');
                $uid = (int)($row['uid'] ?? 0);
                if ($id === '' || $uid <= 0) {
                    continue;
                }
                $reason = $this->eligibility->ineligibleReason($uid, (int)($row['language'] ?? 0), $site);
                if ($reason !== null) {
                    $remove[$id] = $reason;
                    $io->writeln(sprintf('  %-22s %-34s %s', $id, $reason, (string)($row['uri'] ?? $row['title'] ?? '')), OutputInterface::VERBOSITY_VERBOSE);
                }
            }
            $offset += count($rows);
        } while ($rows !== [] && $offset < $page->getTotal());

        $byReason = array_count_values($remove);
        arsort($byReason);
        $io->section(sprintf('Site %s · index %s · %d page documents checked', $site->getIdentifier(), $indexName, $checked));
        foreach ($byReason as $reason => $count) {
            $io->writeln(sprintf('  %-34s %d', $reason, $count));
        }
        if ($remove === []) {
            $io->writeln('  nothing to remove');
            return true;
        }
        if ($dryRun) {
            $io->note(sprintf('%d documents would be removed (dry run, -v lists them).', count($remove)));
            return true;
        }
        foreach (array_chunk(array_keys($remove), 500) as $chunk) {
            $client->index($indexName)->deleteDocuments($chunk);
        }
        $io->success(sprintf('%d documents removed.', count($remove)));

        return true;
    }
}

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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Idempotently creates or updates the EXT:index Configuration record that
 * the meilisearch-bridge needs for page indexing. Without this row, pages
 * never enter the queue and the bridge sees no IndexPageEvents.
 *
 * Operators can do this in the BE module too, but the CLI is the only
 * scriptable path for multi-site rollouts and CI provisioning.
 */
#[AsCommand(
    name: 'ws_meilisearch:setup-index-config',
    description: 'Create or update the EXT:index Configuration record for page indexing via meilisearch-bridge.'
)]
final class SetupIndexConfigCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Site identifier')
            ->addOption('levels', null, InputOption::VALUE_REQUIRED, 'How deep below the root page to crawl', '5')
            ->addOption('partial-indexing', null, InputOption::VALUE_REQUIRED, 'Triggers for live updates (comma-separated: datamap, cmdmap, clearcache). Empty = no live updates.', 'datamap,cmdmap')
            ->addOption('file-mounts', null, InputOption::VALUE_REQUIRED, 'Comma-separated sys_filemounts UIDs for file indexing. Empty = pages only.', '')
            ->addOption('file-types', null, InputOption::VALUE_REQUIRED, 'File extractor group names (e.g. "tika") for file_mounts.', '')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'BE label for the record.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteId = (string)$input->getArgument('site');

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteId);
        } catch (\Throwable $e) {
            $io->error('Unknown site: ' . $siteId);
            return Command::FAILURE;
        }

        $rootPid = (int)$site->getRootPageId();
        if ($rootPid <= 0) {
            $io->error('Site has no root page id.');
            return Command::FAILURE;
        }

        $title = (string)$input->getOption('title');
        if ($title === '') {
            $title = $siteId . '-pages';
        }
        $fields = [
            'title' => $title,
            'technology' => 'database',
            'levels' => max(0, (int)$input->getOption('levels')),
            'partial_indexing' => $this->normaliseList((string)$input->getOption('partial-indexing'), ['datamap', 'cmdmap', 'clearcache']),
            'file_mounts' => $this->normaliseUids((string)$input->getOption('file-mounts')),
            'file_types' => $this->normaliseList((string)$input->getOption('file-types'), null),
            'content_indexing' => 1,
            'skip_no_search_pages' => 1,
        ];

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_index_domain_model_configuration');
        $existing = $qb->select('uid')
            ->from('tx_index_domain_model_configuration')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($rootPid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        $conn = $this->connectionPool->getConnectionForTable('tx_index_domain_model_configuration');
        $now = time();
        if ($existing === false) {
            $conn->insert('tx_index_domain_model_configuration', $fields + [
                'pid' => $rootPid,
                'tstamp' => $now,
                'crdate' => $now,
            ]);
            $uid = (int)$conn->lastInsertId();
            $io->success(sprintf('Created IndexConfiguration #%d on pid=%d for site "%s"', $uid, $rootPid, $siteId));
        } else {
            $uid = (int)$existing['uid'];
            $conn->update('tx_index_domain_model_configuration', $fields + ['tstamp' => $now], ['uid' => $uid]);
            $io->success(sprintf('Updated IndexConfiguration #%d on pid=%d for site "%s"', $uid, $rootPid, $siteId));
        }

        $io->writeln('<comment>Next:</comment> queue + consume:');
        $io->writeln('  vendor/bin/typo3 index:queue --limitConfigurationIdentifiers=' . $uid);
        $io->writeln('  vendor/bin/typo3 messenger:consume index --limit=200');
        return Command::SUCCESS;
    }

    /**
     * Trim/dedupe/validate against an allowed enum (null = accept anything).
     *
     * @param list<string>|null $allowed
     */
    private function normaliseList(string $raw, ?array $allowed): string
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $v) => $v !== ''));
        if ($allowed !== null) {
            $items = array_values(array_intersect($items, $allowed));
        }
        return implode(',', array_unique($items));
    }

    private function normaliseUids(string $raw): string
    {
        $uids = array_values(array_filter(array_map('intval', explode(',', $raw)), static fn(int $v) => $v > 0));
        return implode(',', array_unique($uids));
    }
}

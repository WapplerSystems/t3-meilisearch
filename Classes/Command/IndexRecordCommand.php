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
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\IndexerService;

/**
 * (Re)index a single record, a whole table, or remove a record from the index
 * — through the exact IndexerService path the RecordChangeListener uses on a
 * backend save. Complements `ws_meilisearch:reindex` (which is all-or-nothing
 * per site): this lets you back-fill one document type cheaply, e.g. push the
 * knowledge resources after an import without re-extracting and re-embedding
 * the entire file corpus.
 *
 * Note: this does NOT rebuild the index schema. The index settings
 * (filterable/sortable attributes) must already cover the table's fields — run
 * a full `ws_meilisearch:reindex` once when introducing a brand-new document
 * type, then this command for incremental top-ups.
 */
#[AsCommand(
    name: 'ws_meilisearch:index-record',
    description: 'Index a single record or a whole table (or remove a record) via the live indexer path.'
)]
final class IndexRecordCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly IndexerService $indexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('table', InputArgument::REQUIRED, 'Table, e.g. tx_wsmeilisearch_knowledge_resource, tx_news_domain_model_news, sys_file')
            ->addArgument('uid', InputArgument::OPTIONAL, 'Record uid. Omit to (re)index every record the provider yields for the table.')
            ->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: first Meilisearch-configured site)')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the record from the index instead of indexing it (requires uid).')
            ->addOption('force-embed', null, InputOption::VALUE_NONE, 'Re-embed instead of re-using the vector already stored for the document.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $table = (string)$input->getArgument('table');
        $uidArg = $input->getArgument('uid');
        $uid = $uidArg !== null ? (int)$uidArg : null;
        $remove = (bool)$input->getOption('remove');
        $forceEmbed = (bool)$input->getOption('force-embed');

        if ($remove && $uid === null) {
            $io->error('--remove requires a uid.');
            return Command::INVALID;
        }

        $site = $this->resolveSite($input->getArgument('site'));
        if ($site === null) {
            $io->error('No Meilisearch-configured site found.');
            return Command::FAILURE;
        }

        if ($remove) {
            $ok = $this->indexer->removeRecord($table, $uid, $site);
            $io->writeln(sprintf('%s removed %s:%d from site %s.',
                $ok ? '<info>✓</info>' : '<comment>no provider for</comment>', $table, $uid, $site->getIdentifier()));
            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if ($uid !== null) {
            $ok = $this->indexer->indexRecord($table, $uid, $site, $forceEmbed);
            $stats = $this->indexer->getLastStats();
            $io->writeln(sprintf('%s %s:%d on site %s.%s',
                $ok ? '<info>✓ indexed</info>' : '<comment>✗ NOT indexed</comment>',
                $table,
                $uid,
                $site->getIdentifier(),
                $stats !== null ? ' ' . $stats->summary() : ''));
            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        // Whole-table mode.
        $count = $this->indexer->indexTable($table, $site, $forceEmbed);
        if ($count < 0) {
            $io->error(sprintf('No schema provider supports table "%s".', $table));
            return Command::FAILURE;
        }
        $stats = $this->indexer->getLastStats();
        $io->success(sprintf(
            'Table "%s" on site %s: %s',
            $table,
            $site->getIdentifier(),
            $stats?->summary() ?? sprintf('%d document(s) indexed', $count),
        ));
        return ($stats?->failed ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveSite(?string $siteId): ?Site
    {
        if ($siteId !== null) {
            try {
                return $this->siteFinder->getSiteByIdentifier($siteId);
            } catch (\Throwable) {
                return null;
            }
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (trim((string)$site->getSettings()->get('meilisearch.url', '')) !== '') {
                return $site;
            }
        }
        return null;
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Meilisearch\Contracts\DocumentsQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\PageIndexEligibility;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Remove page documents whose page may no longer be found: deleted, hidden,
 * outside its start/end time, "not searchable", inside an excluded page tree,
 * or a translation that is gone.
 *
 * EXT:index only ever adds pages, so without this the index keeps every page
 * that was ever visible — and the chat links to it. Meant to run daily as a
 * scheduler task; with --dry-run it only lists what it would remove.
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: every site with meilisearch.url)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the documents that would be removed');
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

        return $result;
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

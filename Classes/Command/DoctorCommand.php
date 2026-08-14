<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\EmbedderConfigurator;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Per-site health check covering everything a fresh operator gets wrong:
 * Meilisearch URL/key, Tika URL, embedder settings (site vs. what's actually
 * pushed to the index), IndexConfiguration record for page indexing via the
 * EXT:index integration, and a doc-count breakdown by type.
 *
 * Returns non-zero exit code if any site has a hard failure (no Meilisearch,
 * unreachable, etc.). Warnings (missing IndexConfiguration, no embedder) keep
 * exit 0 — they're operator decisions, not breakage.
 */
#[AsCommand(
    name: 'ws_meilisearch:doctor',
    description: 'Health-check every Meilisearch-configured site: reachability, schema, embedder, IndexConfiguration, doc counts.'
)]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SearchEngineFactory $engineFactory,
        private readonly EmbedderConfigurator $embedderConfigurator,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (omit to check all sites)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteId = $input->getArgument('site');

        try {
            $sites = $siteId !== null
                ? [$this->siteFinder->getSiteByIdentifier($siteId)]
                : iterator_to_array($this->siteFinder->getAllSites());
        } catch (\Throwable $e) {
            $io->error('Could not resolve sites: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $hardFailures = 0;
        foreach ($sites as $site) {
            $io->section('Site: ' . $site->getIdentifier());
            $hardFailures += $this->checkSite($io, $site);
        }

        if ($hardFailures > 0) {
            $io->error(sprintf('%d hard failure(s) — see above', $hardFailures));
            return Command::FAILURE;
        }
        $io->success('All checks green.');
        return Command::SUCCESS;
    }

    private function checkSite(SymfonyStyle $io, Site $site): int
    {
        $settings = $site->getSettings();
        $url = trim((string)$settings->get('meilisearch.url', ''));

        if ($url === '') {
            $io->writeln('  <comment>– no meilisearch.url configured, skipping</comment>');
            return 0;
        }

        $hardFailures = 0;

        // 1. Meilisearch reachable + API key
        $client = $this->engineFactory->createClientForSite($site);
        try {
            $health = $client?->health();
            $version = $client?->version();
            $this->ok($io, sprintf('Meilisearch @ %s (status=%s, version=%s)',
                $url,
                (string)($health['status'] ?? '?'),
                (string)($version['pkgVersion'] ?? '?'),
            ));
        } catch (\Throwable $e) {
            $this->fail($io, 'Meilisearch unreachable: ' . $e->getMessage());
            return $hardFailures + 1;
        }

        // 2. Index existence + doc counts by type
        $indexName = $this->engineFactory->getIndexName($site);
        try {
            $stats = $client->index($indexName)->stats();
            $docCount = (int)($stats['numberOfDocuments'] ?? 0);
            $byType = [];
            if ($docCount > 0) {
                $sample = $client->index($indexName)->search('', [
                    'limit' => 1000,
                    'attributesToRetrieve' => ['type'],
                ]);
                foreach (($sample->getHits() ?? []) as $hit) {
                    $t = (string)($hit['type'] ?? '?');
                    $byType[$t] = ($byType[$t] ?? 0) + 1;
                }
            }
            $breakdown = $byType === []
                ? '(empty)'
                : implode(', ', array_map(fn($t, $n) => "$t=$n", array_keys($byType), $byType));
            $this->ok($io, sprintf('Index "%s": %d documents (%s)', $indexName, $docCount, $breakdown));
        } catch (\Throwable $e) {
            $this->fail($io, 'Index "' . $indexName . '" inaccessible: ' . $e->getMessage());
            $hardFailures++;
        }

        // 3. Tika reachability (if configured)
        $tikaUrl = trim((string)$settings->get('meilisearch.tika.url', ''));
        if ($tikaUrl !== '') {
            $tikaStatus = $this->probeTika($tikaUrl, (int)$settings->get('meilisearch.tika.timeout', 30));
            if ($tikaStatus['ok']) {
                $this->ok($io, sprintf('Tika @ %s (%s)', $tikaUrl, $tikaStatus['version']));
            } else {
                $this->fail($io, 'Tika @ ' . $tikaUrl . ' unreachable: ' . $tikaStatus['error']);
                $hardFailures++;
            }
        } else {
            $this->note($io, 'No meilisearch.tika.url — file extraction (PDF/Office) disabled');
        }

        // 4. Embedder (site settings vs Meilisearch /settings/embedders)
        $embedderSource = trim((string)$settings->get('meilisearch.embedder.source', ''));
        if ($embedderSource !== '') {
            try {
                $current = $client->index($indexName)->getEmbedders();
                if (isset($current[EmbedderConfigurator::EMBEDDER_NAME])) {
                    $this->ok($io, sprintf('Embedder "%s" pushed (source=%s)',
                        EmbedderConfigurator::EMBEDDER_NAME,
                        (string)($current[EmbedderConfigurator::EMBEDDER_NAME]['source'] ?? '?'),
                    ));
                } else {
                    $this->warn($io, 'Embedder configured in site settings but NOT pushed to Meilisearch — run ws_meilisearch:reindex');
                }
            } catch (\Throwable $e) {
                $this->warn($io, 'Could not read embedder state: ' . $e->getMessage());
            }
        } else {
            $this->note($io, 'No embedder configured — hybrid/semantic search unavailable, keyword search still works');
        }

        // 5. IndexConfiguration for page indexing via bridge
        $rootPid = (int)($site->getRootPageId() ?? 0);
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('tx_index_domain_model_configuration');
            $row = $qb->select('uid', 'title', 'technology', 'levels', 'partial_indexing', 'content_indexing')
                ->from('tx_index_domain_model_configuration')
                ->where(
                    $qb->expr()->eq('pid', $qb->createNamedParameter($rootPid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $qb->expr()->eq('deleted', 0),
                )
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            // EXT:index might not be installed at all.
            $row = false;
        }
        if ($row === false) {
            $this->warn($io, sprintf(
                'No EXT:index Configuration on root page %d — pages will NOT be indexed. Run: ws_meilisearch:setup-index-config %s',
                $rootPid,
                $site->getIdentifier(),
            ));
        } else {
            $partial = (string)($row['partial_indexing'] ?? '');
            $partialNote = $partial !== '' ? "partial=$partial" : 'no live updates';
            $this->ok($io, sprintf(
                'IndexConfiguration #%d "%s" (tech=%s, levels=%d, %s)',
                (int)$row['uid'],
                (string)$row['title'],
                (string)$row['technology'],
                (int)$row['levels'],
                $partialNote,
            ));
            // Per-content-element indexing does not survive our document id
            // strategy: every element of a page is pushed as `pages-<uid>`,
            // so the last one wins and the rest of the page is lost. One
            // aggregated document per page is what this integration expects.
            if ((int)($row['content_indexing'] ?? 0) === 1) {
                $this->warn($io, sprintf(
                    'IndexConfiguration #%d has content_indexing=1 — each content element overwrites the page document, only the last one is indexed. Set it to 0.',
                    (int)$row['uid'],
                ));
            }
        }

        // 6. EXT:index integration — pages flow through it
        if (class_exists(\Lochmueller\Index\Event\IndexPageEvent::class)) {
            $this->ok($io, 'EXT:index integration wired (lochmueller/index loaded)');
        } else {
            $this->warn($io, 'lochmueller/index NOT installed — pages will not flow into the index');
        }

        return $hardFailures;
    }

    /**
     * @return array{ok:bool,version:string,error:string}
     */
    private function probeTika(string $url, int $timeout): array
    {
        $url = rtrim($url, '/') . '/version';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min($timeout, 10)),
            CURLOPT_TIMEOUT => max(1, $timeout),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close intentionally omitted — deprecated since PHP 8.0,
        // resource is freed on $ch garbage collection.
        if ($body === false || $http >= 400) {
            return ['ok' => false, 'version' => '', 'error' => $err !== '' ? $err : 'HTTP ' . $http];
        }
        return ['ok' => true, 'version' => trim((string)$body), 'error' => ''];
    }

    private function ok(SymfonyStyle $io, string $msg): void { $io->writeln('  <info>✓</info> ' . $msg); }
    private function warn(SymfonyStyle $io, string $msg): void { $io->writeln('  <comment>!</comment> ' . $msg); }
    private function fail(SymfonyStyle $io, string $msg): void { $io->writeln('  <fg=red>✗</> ' . $msg); }
    private function note(SymfonyStyle $io, string $msg): void { $io->writeln('  <fg=gray>–</> ' . $msg); }
}

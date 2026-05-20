<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Task;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use WapplerSystems\Meilisearch\Service\IndexerService;

/**
 * Periodic full-corpus reindex.
 *
 * Default mode is incremental: existing docs get re-pushed in place, which
 * picks up content edits made since the last run without an availability
 * window. Enable `rebuild` for a destructive drop+recreate — only useful
 * after schema changes (e.g. new field added by a SchemaProvider).
 *
 * Configuration lives in dedicated tx_wsmeilisearch_* columns on
 * tx_scheduler_task; TCA renders them under the regular timing / access tabs.
 */
final class FullReindexTask extends AbstractTask
{
    /**
     * Empty = iterate all sites.
     */
    public string $tx_wsmeilisearch_site_identifier = '';

    public bool $tx_wsmeilisearch_rebuild = false;

    public bool $tx_wsmeilisearch_skip_embedder = false;

    public function execute(): bool
    {
        // No DI inside scheduler execution — instantiate via container-aware factory.
        $indexer = GeneralUtility::makeInstance(IndexerService::class);
        $finder = GeneralUtility::makeInstance(SiteFinder::class);

        $sites = $this->tx_wsmeilisearch_site_identifier !== ''
            ? [$finder->getSiteByIdentifier($this->tx_wsmeilisearch_site_identifier)]
            : $finder->getAllSites();

        $ok = true;
        $total = 0;
        foreach ($sites as $site) {
            if (!$indexer->ensureSchema($site, $this->tx_wsmeilisearch_rebuild, $this->tx_wsmeilisearch_skip_embedder)) {
                // Site is configured for TYPO3 but not for Meilisearch — skip
                // rather than fail; mixed setups (some sites with, some
                // without) are a common deployment pattern.
                continue;
            }
            $count = $indexer->indexAll($site);
            $total += $count;
            $this->logger?->info('Meilisearch reindex: site={site}, docs={count}', [
                'site' => $site->getIdentifier(),
                'count' => $count,
                'rebuild' => $this->tx_wsmeilisearch_rebuild,
            ]);
        }

        return $ok;
    }

    public function setTaskParameters(array $parameters): void
    {
        $this->tx_wsmeilisearch_site_identifier = (string)($parameters['tx_wsmeilisearch_site_identifier'] ?? '');
        $this->tx_wsmeilisearch_rebuild = (bool)($parameters['tx_wsmeilisearch_rebuild'] ?? false);
        $this->tx_wsmeilisearch_skip_embedder = (bool)($parameters['tx_wsmeilisearch_skip_embedder'] ?? false);
    }

    public function getAdditionalInformation(): string
    {
        $parts = [];
        $parts[] = 'site: ' . ($this->tx_wsmeilisearch_site_identifier !== '' ? $this->tx_wsmeilisearch_site_identifier : '(all)');
        if ($this->tx_wsmeilisearch_rebuild) {
            $parts[] = 'rebuild';
        }
        if ($this->tx_wsmeilisearch_skip_embedder) {
            $parts[] = 'skip-embedder';
        }
        return implode(', ', $parts);
    }
}

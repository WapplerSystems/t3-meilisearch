<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use Meilisearch\Client;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Optional hook for SchemaProviders that need to evict stale documents
 * from the search index before a full reindex starts.
 *
 * Motivation: `iterateDocuments` only yields docs the provider still
 * wants to index — it cannot reach docs that USED to be eligible but
 * no longer match (e.g. a sys_file row whose path now matches the
 * operator's `excludeIdentifierPrefixes` site setting). Those become
 * orphans in Meilisearch: invisible to the FE filter but still
 * occupying disk + skewing facet counts.
 *
 * The IndexerService calls `cleanupBeforeReindex` once per provider at
 * the start of `indexAll`, before any `iterateDocuments` runs. Provider
 * receives the raw Meilisearch client (delete-by-filter isn't exposed
 * via SEAL) and the per-site index name. Returns the number of docs it
 * dropped so the operator can see the cleanup count in the reindex
 * output.
 *
 * No-op implementations should simply return 0. Failures should be
 * caught + logged inside the provider so the reindex itself isn't
 * derailed by a transient delete error.
 */
interface PreReindexCleanupInterface
{
    /**
     * @return int number of documents deleted (best-effort)
     */
    public function cleanupBeforeReindex(Site $site, Client $client, string $indexName): int;
}

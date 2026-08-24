<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Domain\Schema\PreReindexCleanupInterface;
use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;
use WapplerSystems\Meilisearch\Event\BeforeDocumentIndexedEvent;
use WapplerSystems\Meilisearch\Service\Indexing\DocumentBatchWriter;
use WapplerSystems\Meilisearch\Service\Indexing\IndexWriteStats;

/**
 * Orchestrates indexing: iterates schema providers, fetches documents, pushes
 * them to the unified per-site index. Provides both full-rebuild and
 * single-record operations (the latter used by the DataHandler hook).
 */
final class IndexerService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param iterable<SchemaProviderInterface> $schemaProviders
     */
    public function __construct(
        private readonly iterable $schemaProviders,
        private readonly SearchEngineFactory $engineFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EmbedderConfigurator $embedderConfigurator,
        private readonly EmbeddingPrecomputer $embeddingPrecomputer,
        private readonly IndexSettingsApplier $indexSettingsApplier,
    ) {}

    /**
     * Breakdown of the most recent indexing run — how many documents were
     * skipped as unchanged, how many re-used their existing vector and how
     * many actually cost an embedding call. The CLI commands print it; it
     * is the only way to tell a cheap routine reindex from one that is
     * about to run into the provider's token quota.
     */
    private ?IndexWriteStats $lastStats = null;

    public function getLastStats(): ?IndexWriteStats
    {
        return $this->lastStats;
    }

    /**
     * Assemble the buffered writer for one run. `readIndex` is where
     * existing fingerprints and vectors are looked up: the primary index
     * even when writing into a draft, because the draft starts empty.
     */
    private function createWriter(
        Site $site,
        ?\CmsIg\Seal\Engine $engine,
        ?\Meilisearch\Client $client,
        string $writeIndex,
        ?string $readIndex,
        bool $allowSkip,
        bool $forceEmbed,
        bool $strict,
        ?callable $progress = null,
    ): DocumentBatchWriter {
        $writer = new DocumentBatchWriter(
            $site,
            $client,
            $engine,
            $writeIndex,
            $readIndex,
            $this->embeddingPrecomputer,
            $this->eventDispatcher,
            $this->embeddingPrecomputer->isEnabledForSite($site),
            $allowSkip,
            $forceEmbed,
            $strict,
        );
        if ($this->logger !== null) {
            $writer->setLogger($this->logger);
        }
        $writer->setProgressCallback($progress);
        return $writer;
    }

    /**
     * Site-setting–driven choice of where the next indexAll() writes:
     *  - false (default): primary index, drop-and-rebuild semantics.
     *    Visitors get `no_context` / blank search results during reindex.
     *  - true: draft index, swapped in atomically at the end. Search
     *    keeps serving the previous corpus until the swap second.
     */
    private function zeroDowntimeEnabled(Site $site): bool
    {
        return (bool)$site->getSettings()->get('meilisearch.indexing.zeroDowntime', false);
    }

    /**
     * Resolve where the next indexing run should write documents.
     * Same as getIndexName() in the legacy mode; suffix-_draft in
     * zero-downtime mode.
     */
    private function writeTargetIndexName(Site $site): string
    {
        return $this->zeroDowntimeEnabled($site)
            ? $this->engineFactory->getDraftIndexName($site)
            : $this->engineFactory->getIndexName($site);
    }

    public function ensureSchema(Site $site, bool $rebuild = false, bool $skipEmbedder = false): bool
    {
        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            return false;
        }
        $writeTarget = $this->writeTargetIndexName($site);
        $zeroDowntime = $this->zeroDowntimeEnabled($site);

        // Wait for index + settings tasks to finish — Meilisearch processes
        // `createIndex` and `updateSettings` asynchronously, and indexing
        // documents before `filterableAttributes` has been applied yields
        // "Invalid facet distribution" errors on the first searches.
        $options = ['return_slow_promise_result' => true];

        // In zero-downtime mode the draft index is always recreated from
        // scratch — there's no point inheriting a half-finished previous
        // attempt, and the visitor-facing primary index keeps serving
        // the old corpus until the swap happens at the end of indexAll().
        // In legacy mode --rebuild controls the drop, and an already-
        // existing primary is left alone (overwrite-in-place reindex).
        if ($zeroDowntime) {
            if ($engine->existIndex($writeTarget)) {
                $engine->dropIndex($writeTarget, $options)?->wait();
            }
        } elseif ($rebuild && $engine->existIndex($writeTarget)) {
            $engine->dropIndex($writeTarget, $options)?->wait();
        }
        if (!$engine->existIndex($writeTarget)) {
            $engine->createIndex($writeTarget, $options)?->wait();
        }

        // Push the full index settings (rankingRules, filterableAttributes,
        // sortableAttributes, typoTolerance, …) to the WRITE TARGET so the
        // draft index is search-ready the moment the swap happens. Without
        // this the swap would promote an empty-settings index to primary
        // and effectively roll back all relevance tuning.
        $this->indexSettingsApplier->applyTo($site, $writeTarget);

        // Push embedder settings *after* the index exists. Operator can
        // suppress this with --skip-embedder when troubleshooting a wedged
        // embedder config — the rest of the index keeps working.
        if (!$skipEmbedder) {
            $this->embedderConfigurator->ensureForSite($site, $writeTarget);
        }
        return true;
    }

    public function indexAll(Site $site, bool $forceEmbed = false, ?callable $progress = null): int
    {
        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            $this->logger?->warning(
                'Meilisearch engine not configured for site {id}',
                ['id' => $site->getIdentifier()],
            );
            return 0;
        }
        $primaryName = $this->engineFactory->getIndexName($site);
        $writeTarget = $this->writeTargetIndexName($site);
        $zeroDowntime = $this->zeroDowntimeEnabled($site);
        // Document-push path uses $writeTarget so zero-downtime writes
        // land in the draft index; everything below treats $indexName
        // as the write destination.
        $indexName = $writeTarget;

        // Pre-reindex orphan cleanup runs against the WRITE TARGET. In
        // legacy mode that's the primary, so we evict stale docs that
        // the iterator no longer reaches. In zero-downtime mode the
        // draft was just recreated empty by ensureSchema(), so cleanup
        // is a no-op there — but the loop is cheap (it's the
        // FileSchemaProvider checking its own filter setting), so we
        // let it run for symmetry.
        $client = $this->engineFactory->createClientForSite($site);
        if ($client !== null) {
            foreach ($this->schemaProviders as $provider) {
                if (!$provider instanceof PreReindexCleanupInterface) {
                    continue;
                }
                $deleted = $provider->cleanupBeforeReindex($site, $client, $indexName);
                if ($deleted > 0) {
                    $this->logger?->info(
                        'Pre-reindex cleanup: {provider} removed {count} orphan docs from site {site}',
                        [
                            'provider' => $provider::class,
                            'count' => $deleted,
                            'site' => $site->getIdentifier(),
                        ],
                    );
                }
            }
        }

        // Documents go through DocumentBatchWriter, which fingerprints
        // each one and only pays the embedding provider for documents
        // whose text actually changed. See that class for why: a naive
        // full reindex re-embedded all ~45k documents and stalled in
        // Scaleway's tokens-per-minute quota.
        //
        // Fingerprints and re-usable vectors are read from the PRIMARY
        // index — in zero-downtime mode the write target is a freshly
        // emptied draft, so it has nothing to compare against. That mode
        // also forbids skipping: every document must physically land in
        // the draft or the swap would publish an index with holes.
        $writer = $this->createWriter(
            $site,
            $engine,
            $client,
            $indexName,
            $primaryName,
            !$zeroDowntime,
            $forceEmbed,
            false,
            $progress,
        );

        foreach ($this->schemaProviders as $provider) {
            foreach ($provider->iterateDocuments($site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                $writer->push($event->document, $provider);
            }
        }
        $writer->flush();
        $this->lastStats = $writer->stats;
        $count = $writer->stats->written + $writer->stats->skipped;

        if ($writer->stats->failed > 0) {
            $this->logger?->error(
                'Reindex of site {site} finished with {failed} documents missing — embedding failed for them, see the errors above',
                ['site' => $site->getIdentifier(), 'failed' => $writer->stats->failed],
            );
        }

        // Zero-downtime cutover: swap the draft (where we just wrote
        // $count docs) with the primary, then drop the now-stale old
        // draft. Meilisearch's swap-indexes API runs atomically — the
        // visitor-facing primary keeps serving the old corpus until
        // the swap second, and after the swap returns the engine has
        // already promoted the new corpus to the primary name.
        // Safety: only swap when at least one document landed in the
        // draft. An empty draft would atomically wipe a working
        // primary, and that's almost certainly a regression we want
        // to surface loudly rather than silently swap to.
        if ($zeroDowntime && $client !== null && $count > 0) {
            try {
                $task = $client->swapIndexes([['indexes' => [$primaryName, $writeTarget]]]);
                $taskUid = (int)($task['taskUid'] ?? 0);
                if ($taskUid > 0) {
                    $client->waitForTask($taskUid);
                }
                // After swap, $writeTarget holds the OLD corpus. Drop
                // it so the next reindex starts from a clean slate and
                // disk usage doesn't accumulate.
                $client->deleteIndex($writeTarget);
                $this->logger?->info(
                    'Zero-downtime swap complete for site {site}: primary {primary} now holds {count} freshly-indexed documents',
                    ['site' => $site->getIdentifier(), 'primary' => $primaryName, 'count' => $count],
                );
            } catch (\Throwable $e) {
                $this->logger?->error(
                    'Zero-downtime swap failed for site {site}: {msg} — draft index {draft} retained for inspection',
                    ['site' => $site->getIdentifier(), 'msg' => $e->getMessage(), 'draft' => $writeTarget, 'exception' => $e],
                );
            }
        } elseif ($zeroDowntime && $count === 0) {
            $this->logger?->warning(
                'Zero-downtime reindex produced 0 documents for site {site} — skipping swap to protect the primary index',
                ['site' => $site->getIdentifier()],
            );
        }

        return $count;
    }

    public function indexRecord(string $table, int $uid, Site $site, bool $forceEmbed = false): bool
    {
        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            return false;
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $client = $this->engineFactory->createClientForSite($site);

        foreach ($this->schemaProviders as $provider) {
            if (!$provider->supports($table)) {
                continue;
            }
            // A single record is written in place, so unchanged documents
            // can be skipped outright — an editor re-saving a record
            // without touching indexed fields costs nothing.
            $writer = $this->createWriter(
                $site,
                $engine,
                $client,
                $indexName,
                $indexName,
                true,
                $forceEmbed,
                false,
            );
            $any = false;
            foreach ($provider->fetchDocuments($uid, $site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                $writer->push($event->document, $provider);
                $any = true;
            }
            $writer->flush();
            $this->lastStats = $writer->stats;
            if (!$any) {
                // Record vanished or got hidden — drop every document variant
                // we might have written previously (per-language, etc.).
                foreach ($provider->buildDocumentIds($uid, $site) as $docId) {
                    $engine->deleteDocument($indexName, $docId);
                }
            }
            // false when embedding failed: the document was deliberately
            // NOT pushed (Meilisearch rejects vectorless documents against
            // the userProvided embedder), so the caller must not treat
            // this record as indexed.
            return $writer->stats->failed === 0;
        }
        return false;
    }

    /**
     * (Re)index every document a single provider yields for $table into the
     * existing index. Mirrors indexAll()'s per-document push but scoped to one
     * table, so e.g. knowledge resources can be back-filled without
     * re-extracting and re-embedding the whole file corpus. No schema rebuild —
     * the index settings (filterable/sortable attributes) are assumed current;
     * run a full reindex once when introducing a brand-new document type.
     *
     * Returns the number of documents pushed, or -1 when no provider supports
     * the table.
     */
    public function indexTable(string $table, Site $site, bool $forceEmbed = false): int
    {
        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            return -1;
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $client = $this->engineFactory->createClientForSite($site);

        foreach ($this->schemaProviders as $provider) {
            if (!$provider->supports($table)) {
                continue;
            }
            $writer = $this->createWriter(
                $site,
                $engine,
                $client,
                $indexName,
                $indexName,
                true,
                $forceEmbed,
                false,
            );
            foreach ($provider->iterateDocuments($site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                $writer->push($event->document, $provider);
            }
            $writer->flush();
            $this->lastStats = $writer->stats;
            return $writer->stats->written + $writer->stats->skipped;
        }
        return -1;
    }

    public function removeRecord(string $table, int $uid, Site $site): bool
    {
        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            return false;
        }
        $indexName = $this->engineFactory->getIndexName($site);

        foreach ($this->schemaProviders as $provider) {
            if (!$provider->supports($table)) {
                continue;
            }
            foreach ($provider->buildDocumentIds($uid, $site) as $docId) {
                $engine->deleteDocument($indexName, $docId);
            }
            return true;
        }
        return false;
    }
}
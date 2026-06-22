<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Domain\Schema\PreReindexCleanupInterface;
use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;
use WapplerSystems\Meilisearch\Event\AfterDocumentIndexedEvent;
use WapplerSystems\Meilisearch\Event\BeforeDocumentIndexedEvent;

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

    public function indexAll(Site $site): int
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

        // When precompute is on, PHP fetches the embedding itself before
        // saveDocument and writes it into `_vectors.default`. The throttle
        // sits inside EmbeddingPrecomputer (token bucket against the
        // provider); the Meilisearch side gets pre-vectorized documents,
        // so there is no embedder fan-out to worry about here.
        //
        // We also bypass the SEAL engine's saveDocument for precompute
        // docs and push directly via the raw Meilisearch client:
        // SEAL's Marshaller copies fields by Schema definition only,
        // and `_vectors` is not in the schema (Meilisearch treats it
        // as a special field at the document level, not a regular
        // field). Routing through saveDocument would silently strip
        // the vector and Meilisearch would reject every document with
        // "no vectors provided for document …" against the
        // userProvided embedder.
        $precompute = $this->embeddingPrecomputer->isEnabledForSite($site);

        $count = 0;
        foreach ($this->schemaProviders as $provider) {
            foreach ($provider->iterateDocuments($site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                $doc = $event->document;
                if ($precompute) {
                    $doc = $this->embeddingPrecomputer->attachEmbedding($site, $doc);
                    if ($client !== null) {
                        $client->index($indexName)->addDocuments([$doc], 'id');
                    } else {
                        // No raw client (site lacks meilisearch.url) — fall
                        // back to engine push; the vector will be stripped
                        // but the doc still lands for keyword search.
                        $engine->saveDocument($indexName, $doc);
                    }
                } else {
                    $engine->saveDocument($indexName, $doc);
                }
                $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($provider, $doc));
                $count++;
            }
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

    public function indexRecord(string $table, int $uid, Site $site): bool
    {
        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            return false;
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $precompute = $this->embeddingPrecomputer->isEnabledForSite($site);
        // Raw Meilisearch client for the precompute-push path — see the
        // long comment in indexAll() about why SEAL's saveDocument
        // strips `_vectors`.
        $client = $precompute ? $this->engineFactory->createClientForSite($site) : null;

        foreach ($this->schemaProviders as $provider) {
            if (!$provider->supports($table)) {
                continue;
            }
            $any = false;
            foreach ($provider->fetchDocuments($uid, $site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                $doc = $event->document;
                if ($precompute) {
                    $doc = $this->embeddingPrecomputer->attachEmbedding($site, $doc);
                    if ($client !== null) {
                        $client->index($indexName)->addDocuments([$doc], 'id');
                    } else {
                        $engine->saveDocument($indexName, $doc);
                    }
                } else {
                    $engine->saveDocument($indexName, $doc);
                }
                $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($provider, $doc));
                $any = true;
            }
            if (!$any) {
                // Record vanished or got hidden — drop every document variant
                // we might have written previously (per-language, etc.).
                foreach ($provider->buildDocumentIds($uid, $site) as $docId) {
                    $engine->deleteDocument($indexName, $docId);
                }
            }
            return true;
        }
        return false;
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
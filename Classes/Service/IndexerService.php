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
    ) {}

    public function ensureSchema(Site $site, bool $rebuild = false, bool $skipEmbedder = false): bool
    {
        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            return false;
        }
        $indexName = $this->engineFactory->getIndexName($site);

        // Wait for index + settings tasks to finish — Meilisearch processes
        // `createIndex` and `updateSettings` asynchronously, and indexing
        // documents before `filterableAttributes` has been applied yields
        // "Invalid facet distribution" errors on the first searches.
        $options = ['return_slow_promise_result' => true];

        if ($rebuild && $engine->existIndex($indexName)) {
            $engine->dropIndex($indexName, $options)?->wait();
        }
        if (!$engine->existIndex($indexName)) {
            $engine->createIndex($indexName, $options)?->wait();
        }

        // Push embedder settings *after* the index exists. Operator can
        // suppress this with --skip-embedder when troubleshooting a wedged
        // embedder config — the rest of the index keeps working.
        if (!$skipEmbedder) {
            $this->embedderConfigurator->ensureForSite($site);
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
        $indexName = $this->engineFactory->getIndexName($site);

        // Pre-reindex orphan cleanup: providers that implement
        // PreReindexCleanupInterface (currently only FileSchemaProvider,
        // for the excludeIdentifierPrefixes site setting) get a chance to
        // drop docs that USED to be eligible but no longer are. Without
        // this, the iterator can't reach them — they'd stay orphaned
        // forever. Skip when a fresh client isn't available (site without
        // url config) since cleanup needs the raw Meilisearch client to
        // call delete-by-filter (SEAL doesn't expose it).
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
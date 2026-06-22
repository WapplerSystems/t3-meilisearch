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

        // Throttle reindex push rate when the embedding provider enforces
        // a per-minute quota (Scaleway Generative APIs: 60–600 RPM per
        // model depending on tier; Infomaniak: 60 RPM hard). When set,
        // each saveDocument wait synchronously for the Meilisearch task
        // to finish (which in turn waits for the embedding to complete),
        // and a sleep enforces a minimum interval between pushes. With
        // no setting (or 0), behaviour is the legacy fire-and-forget.
        $rpm = (int)$site->getSettings()->get('meilisearch.indexing.requestsPerMinute', 0);
        $minIntervalUs = $rpm > 0 ? (int)(60_000_000 / $rpm) : 0;
        $pushOptions = $rpm > 0 ? ['return_slow_promise_result' => true] : [];
        $lastPushAtUs = 0;

        $count = 0;
        foreach ($this->schemaProviders as $provider) {
            foreach ($provider->iterateDocuments($site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                if ($minIntervalUs > 0) {
                    $nowUs = (int)(microtime(true) * 1_000_000);
                    $waitUs = $minIntervalUs - ($nowUs - $lastPushAtUs);
                    if ($waitUs > 0) {
                        usleep($waitUs);
                    }
                    $lastPushAtUs = (int)(microtime(true) * 1_000_000);
                }
                $task = $engine->saveDocument($indexName, $event->document, $pushOptions);
                if ($task !== null) {
                    // wait() blocks until the Meilisearch task is no longer
                    // 'processing' — which includes the embedding fetch —
                    // so the next loop iteration cannot pile a second
                    // concurrent embedding request on the provider.
                    $task->wait();
                }
                $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($provider, $event->document));
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

        foreach ($this->schemaProviders as $provider) {
            if (!$provider->supports($table)) {
                continue;
            }
            $any = false;
            foreach ($provider->fetchDocuments($uid, $site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                $engine->saveDocument($indexName, $event->document);
                $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($provider, $event->document));
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
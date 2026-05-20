<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
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
    ) {}

    public function ensureSchema(Site $site, bool $rebuild = false): bool
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

        $count = 0;
        foreach ($this->schemaProviders as $provider) {
            foreach ($provider->iterateDocuments($site) as $document) {
                $event = new BeforeDocumentIndexedEvent($provider, $document);
                $this->eventDispatcher->dispatch($event);
                $engine->saveDocument($indexName, $event->document);
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
            $document = $provider->fetchDocument($uid);
            if ($document === null) {
                // Record vanished or got hidden — make sure it's gone from the index too.
                $engine->deleteDocument($indexName, $provider->buildDocumentId($uid));
                return true;
            }
            $event = new BeforeDocumentIndexedEvent($provider, $document);
            $this->eventDispatcher->dispatch($event);
            $engine->saveDocument($indexName, $event->document);
            $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($provider, $event->document));
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
            $engine->deleteDocument($indexName, $provider->buildDocumentId($uid));
            return true;
        }
        return false;
    }
}
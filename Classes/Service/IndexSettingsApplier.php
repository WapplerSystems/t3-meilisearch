<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;

/**
 * Push the index-level Meilisearch settings (rankingRules,
 * typoTolerance, stopWords, synonyms, filterableAttributes,
 * sortableAttributes, …) from Site Settings to a named index. Shared
 * by the ApplyMeilisearchSettingsCommand and by the zero-downtime
 * reindex flow in IndexerService — the latter must push the same
 * settings to the draft index before swapping, because Meilisearch's
 * swap-indexes API also swaps all index settings, so a draft that
 * never got its rankingRules / filterableAttributes pushed would
 * silently roll the engine back to engine defaults on swap.
 *
 * Distinct from EmbedderConfigurator: that one only manages the
 * embedder entry (which has its own dedicated REST endpoint and a
 * separate diff/no-op pass).
 */
final class IndexSettingsApplier implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        private readonly SearchConfigurationProvider $configProvider,
    ) {}

    /**
     * Push the configured settings to the given index, creating the
     * index first if it doesn't exist. Returns whether the engine
     * accepted the settings; logs + returns false on error.
     */
    public function applyTo(Site $site, string $indexName, bool $wait = true): bool
    {
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return false;
        }
        $payload = $this->configProvider->indexSettings($site)->toMeilisearchPayload();
        $payload = $this->mergeSchemaAttributes($payload, $site);
        try {
            $this->ensureIndexExists($client, $indexName, $wait);
            $task = $client->index($indexName)->updateSettings($payload);
            $taskUid = (int)($task['taskUid'] ?? 0);
            if ($wait && $taskUid > 0) {
                $client->index($indexName)->waitForTask($taskUid);
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error(
                'Failed to apply Meilisearch settings to index {index}: {msg}',
                ['index' => $indexName, 'msg' => $e->getMessage(), 'exception' => $e],
            );
            return false;
        }
    }

    private function ensureIndexExists(Client $client, string $indexName, bool $wait): void
    {
        try {
            $client->getIndex($indexName);
            return;
        } catch (ApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
        }
        $task = $client->createIndex($indexName, ['primaryKey' => 'id']);
        $taskUid = (int)($task['taskUid'] ?? 0);
        if ($wait && $taskUid > 0) {
            $client->waitForTask($taskUid);
        }
    }

    /**
     * Same merge as in ApplyMeilisearchSettingsCommand —
     * searchableAttributes / filterableAttributes / sortableAttributes
     * derived from the SEAL schema, since SEAL only pushes them on
     * initial createIndex() and a draft index needs the current shape
     * applied explicitly.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mergeSchemaAttributes(array $payload, Site $site): array
    {
        $schema = $this->engineFactory->getSchemaForSite($site);
        $indexName = $this->engineFactory->getIndexName($site);
        $index = $schema->indexes[$indexName] ?? null;
        if ($index === null) {
            return $payload;
        }
        $filterable = array_values(array_unique(array_merge(
            $index->filterableFields,
            $index->distinctFields,
            $index->facetFields,
        )));
        $payload['searchableAttributes'] = $index->searchableFields;
        $payload['filterableAttributes'] = $filterable;
        $payload['sortableAttributes']   = $index->sortableFields;
        return $payload;
    }
}

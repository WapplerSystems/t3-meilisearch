<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Pushes the per-site embedder configuration to Meilisearch.
 *
 * Embedders live on the index itself (PATCH /indexes/{uid}/settings/embedders);
 * once configured, Meilisearch auto-vectorizes every document on save and
 * exposes hybrid (keyword + semantic) search on the same index.
 *
 * Idempotent — diffs current vs. desired and only PATCHes when something
 * actually changed, so the bootstrap path in IndexerService::ensureSchema can
 * call this on every reindex without thrashing the index.
 */
final class EmbedderConfigurator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * SEAL adapter writes embedder config under the key "default" because that
     * is the only embedder name we expose to the frontend. Multi-embedder
     * setups (e.g. one for title, one for body) are out of scope for Phase 3.
     */
    public const EMBEDDER_NAME = 'default';

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
    ) {}

    /**
     * Push the desired embedder state. Returns:
     *  - 'configured' — settings changed (or first-time setup)
     *  - 'unchanged' — already in the desired state
     *  - 'disabled'  — site has no embedder configured; existing one (if any) was cleared
     *  - 'skipped'   — site has no Meilisearch backend at all
     */
    public function ensureForSite(Site $site): string
    {
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return 'skipped';
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $index = $client->index($indexName);

        $desired = $this->buildDesiredEmbedders($site);
        $current = $this->fetchCurrentEmbedders($index);

        if ($this->normalize($current) === $this->normalize($desired)) {
            return $desired === [] ? 'disabled' : 'unchanged';
        }

        if ($desired === []) {
            $task = $index->resetEmbedders();
            $this->waitForTask($client, $task, $site);
            $this->logger?->info(
                'Meilisearch embedders cleared for site {id}',
                ['id' => $site->getIdentifier()],
            );
            return 'disabled';
        }

        // Wait synchronously — if a later documentAdditionOrUpdate fires before
        // the embedder settings are committed, Meilisearch silently keeps the
        // old (or empty) embedder, and the whole reindex produces unvectorized
        // docs that hybrid search will ignore.
        $task = $index->updateEmbedders($desired);
        $this->waitForTask($client, $task, $site);
        $this->logger?->info(
            'Meilisearch embedder configured for site {id}',
            ['id' => $site->getIdentifier()],
        );
        return 'configured';
    }

    /**
     * @param array<string,mixed> $task
     */
    private function waitForTask(\Meilisearch\Client $client, array $task, Site $site): void
    {
        $taskUid = $task['taskUid'] ?? $task['uid'] ?? null;
        if ($taskUid === null) {
            return;
        }
        $resolved = $client->waitForTask((int)$taskUid);
        if (($resolved['status'] ?? '') !== 'failed') {
            return;
        }
        $error = $resolved['error'] ?? [];
        $message = is_array($error) ? ($error['message'] ?? 'unknown error') : (string)$error;
        // Surface as exception — the caller (IndexerService::ensureSchema) is
        // about to push documents that depend on this configuration. Failing
        // loudly is better than silently producing a half-indexed corpus.
        throw new \RuntimeException(sprintf(
            'Meilisearch embedder update failed for site "%s" (task %d): %s',
            $site->getIdentifier(),
            (int)$taskUid,
            $message,
        ));
    }

    /**
     * Which embedder fields each Meilisearch source accepts. Sending an
     * unrelated field (e.g. documentTemplate on userProvided) is rejected
     * with a hard 400, breaking the entire reindex.
     *
     * @var array<string,list<string>>
     */
    private const SOURCE_FIELDS = [
        'openAi'       => ['model', 'apiKey', 'dimensions', 'documentTemplate', 'url'],
        'huggingFace'  => ['model', 'dimensions', 'documentTemplate'],
        'ollama'       => ['url', 'model', 'apiKey', 'dimensions', 'documentTemplate'],
        'rest'         => ['url', 'apiKey', 'dimensions', 'documentTemplate'],
        'userProvided' => ['dimensions'],
    ];

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildDesiredEmbedders(Site $site): array
    {
        $settings = $site->getSettings();
        $source = trim((string)$settings->get('meilisearch.embedder.source', ''));
        if ($source === '') {
            return [];
        }

        // Infomaniak isn't a native Meilisearch source — it's a preset that
        // resolves to the OpenAI-compatible source with a derived URL, so the
        // user only has to fill productId + model + apiKey.
        if ($source === 'infomaniak') {
            return $this->buildInfomaniakEmbedder($site);
        }

        $allowed = self::SOURCE_FIELDS[$source] ?? null;
        if ($allowed === null) {
            $this->logger?->warning(
                'Unknown embedder source "{source}" for site {id} — skipping embedder push',
                ['source' => $source, 'id' => $site->getIdentifier()],
            );
            return [];
        }

        $embedder = ['source' => $source];
        foreach ($allowed as $field) {
            $value = $settings->get('meilisearch.embedder.' . $field, null);
            if ($field === 'dimensions') {
                $dims = (int)$value;
                if ($dims > 0) {
                    $embedder['dimensions'] = $dims;
                }
                continue;
            }
            $stringValue = trim((string)$value);
            if ($stringValue !== '') {
                $embedder[$field] = $stringValue;
            }
        }

        return [self::EMBEDDER_NAME => $embedder];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildInfomaniakEmbedder(Site $site): array
    {
        $settings = $site->getSettings();
        $productId = trim((string)$settings->get('meilisearch.infomaniak.productId', ''));
        if ($productId === '') {
            $this->logger?->warning(
                'Infomaniak embedder preset requires meilisearch.infomaniak.productId for site {id} — skipping embedder push',
                ['id' => $site->getIdentifier()],
            );
            return [];
        }
        $embedder = [
            'source' => 'openAi',
            'url' => 'https://api.infomaniak.com/1/ai/' . rawurlencode($productId) . '/openai/embeddings',
        ];
        foreach (['model', 'apiKey', 'documentTemplate'] as $field) {
            $value = trim((string)$settings->get('meilisearch.embedder.' . $field, ''));
            if ($value !== '') {
                $embedder[$field] = $value;
            }
        }
        $dims = (int)$settings->get('meilisearch.embedder.dimensions', 0);
        if ($dims > 0) {
            $embedder['dimensions'] = $dims;
        }
        return [self::EMBEDDER_NAME => $embedder];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function fetchCurrentEmbedders(\Meilisearch\Endpoints\Indexes $index): array
    {
        try {
            $embedders = $index->getEmbedders();
        } catch (\Throwable $e) {
            // Most common cause: index does not exist yet. Treat as "no embedders".
            return [];
        }
        return is_array($embedders) ? $embedders : [];
    }

    /**
     * Build a stable, comparable representation. apiKey is excluded from the
     * diff because Meilisearch redacts it on read-back ("sk-...XXXX"), so the
     * raw vs. redacted strings would never match and we'd PATCH on every call.
     * Trade-off: if an operator rotates *only* the apiKey, the change isn't
     * detected — they need to also touch another field or run a forced sync.
     *
     * @param array<string,array<string,mixed>> $embedders
     * @return array<string,array<string,mixed>>
     */
    private function normalize(array $embedders): array
    {
        // Same list as buildDesiredEmbedders but with `source` included.
        // apiKey is excluded — Meilisearch redacts it on read-back.
        $allowed = ['source', 'model', 'url', 'dimensions', 'documentTemplate'];
        $out = [];
        foreach ($embedders as $name => $config) {
            if (!is_array($config)) {
                continue;
            }
            $filtered = [];
            foreach ($allowed as $key) {
                if (!array_key_exists($key, $config)) {
                    continue;
                }
                $value = $config[$key];
                if ($value === null || $value === '') {
                    continue;
                }
                $filtered[$key] = $key === 'dimensions' ? (int)$value : (string)$value;
            }
            ksort($filtered);
            $out[(string)$name] = $filtered;
        }
        ksort($out);
        return $out;
    }
}
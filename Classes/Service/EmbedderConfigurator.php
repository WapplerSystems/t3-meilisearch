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
    public function ensureForSite(Site $site, ?string $indexName = null): string
    {
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return 'skipped';
        }
        // Allow the caller to override the index name — IndexerService's
        // zero-downtime flow writes to a draft index and needs the embedder
        // settings pushed there, not to the live primary.
        $indexName ??= $this->engineFactory->getIndexName($site);
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
        // Embedder settings updates trigger Meilisearch to embed every
        // existing document — on a 40k-doc index that can take a couple of
        // minutes at provider-side rate limits. Wait long enough to catch
        // a quickly-failing config (bad URL, bad key, bad model) but cap so
        // a slow background re-embed doesn't hang the reindex. If the task
        // is still running when the cap hits, log + continue; Meilisearch
        // finishes the work on its own and the new docs we're about to
        // write will get vectors as they're saved.
        try {
            $resolved = $client->waitForTask((int)$taskUid, 30_000, 500);
        } catch (\Meilisearch\Exceptions\TimeOutException) {
            $this->logger?->info(
                'Meilisearch embedder update task {uid} for site {id} is still running after 30s — proceeding with reindex; vectors fill in as the background task completes.',
                ['uid' => (int)$taskUid, 'id' => $site->getIdentifier()],
            );
            return;
        }
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

        // Precompute mode: PHP fetches the embedding itself (with a real
        // token-bucket against the provider) and writes the vector into
        // the doc's `_vectors.default` field. Meilisearch's job shrinks
        // to storing the precomputed vector — register a `userProvided`
        // embedder with the right dimensions so hybrid search wires up.
        if ((bool)$settings->get('meilisearch.embedder.precompute', false) === true) {
            $dims = (int)$settings->get('meilisearch.embedder.dimensions', 0);
            if ($dims <= 0) {
                $this->logger?->warning(
                    'Precompute mode requires meilisearch.embedder.dimensions > 0 for site {id} — skipping embedder push',
                    ['id' => $site->getIdentifier()],
                );
                return [];
            }
            return [self::EMBEDDER_NAME => ['source' => 'userProvided', 'dimensions' => $dims]];
        }

        // Infomaniak isn't a native Meilisearch source — it's a preset that
        // resolves to the OpenAI-compatible source with a derived URL, so the
        // user only has to fill productId + model + apiKey.
        if ($source === 'infomaniak') {
            return $this->buildInfomaniakEmbedder($site);
        }
        // Same idea for Scaleway Generative APIs (Paris, EU-sovereign).
        // OpenAI-compatible /v1/embeddings, standard model names with
        // dashes. Provider chosen for materially higher burst-rate
        // tolerance than Infomaniak (200 parallel/9s vs 60/min) which
        // matters for large reindex runs.
        if ($source === 'scaleway') {
            return $this->buildScalewayEmbedder($site);
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
            if ($field === 'url' && $source === 'ollama') {
                // Meilisearch insists on Ollama's NATIVE endpoint here
                // ("unsupported Ollama URL … must end with /api/embed or
                // /api/embeddings"), while the precompute path in
                // EmbeddingPrecomputer speaks the OpenAI-compatible
                // /v1/embeddings dialect against the same server. One
                // setting, two incompatible shapes — so each consumer
                // coerces it instead of making the integrator guess
                // which one the current `precompute` value needs.
                $stringValue = self::normaliseOllamaUrl($stringValue, false);
            }
            if ($stringValue !== '') {
                $embedder[$field] = $stringValue;
            }
        }

        return [self::EMBEDDER_NAME => $embedder];
    }

    /**
     * Point an Ollama base URL at the endpoint the caller needs.
     *
     * `$openAiCompatible` selects between the two dialects the same
     * server exposes: `/v1/embeddings` (OpenAI shape, used by
     * EmbeddingPrecomputer) and `/api/embeddings` (native, the only one
     * Meilisearch accepts for `source: ollama`).
     *
     * A known endpoint suffix is stripped before the wanted one is
     * appended, so a reverse-proxy prefix such as
     * `https://ai.example.com/ollama/v1/embeddings` survives the
     * rewrite as `https://ai.example.com/ollama/api/embeddings`.
     */
    public static function normaliseOllamaUrl(string $url, bool $openAiCompatible): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            // Not a URL we can reason about — hand it back untouched and
            // let the provider complain rather than mangling it.
            return $url;
        }
        $base = ($parts['scheme'] ?? 'http') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');
        foreach (['/v1/embeddings', '/api/embeddings', '/api/embed'] as $suffix) {
            if (str_ends_with($path, $suffix)) {
                $path = substr($path, 0, -strlen($suffix));
                break;
            }
        }
        return $base . $path . ($openAiCompatible ? '/v1/embeddings' : '/api/embeddings');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildInfomaniakEmbedder(Site $site): array
    {
        $settings = $site->getSettings();
        $productId = trim((string)$settings->get('meilisearch.infomaniak.productId', ''));
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        if ($productId === '' || $model === '') {
            $this->logger?->warning(
                'Infomaniak embedder preset requires meilisearch.infomaniak.productId AND meilisearch.embedder.model for site {id} — skipping embedder push',
                ['id' => $site->getIdentifier()],
            );
            return [];
        }
        // Meilisearch's `openAi` source validates the model name against
        // OpenAI's own catalogue (text-embedding-3-*, ada-002, …) since
        // v1.11, so OpenAI-compatible providers like Infomaniak need the
        // generic `rest` source with explicit request/response templates.
        // The templates use Meilisearch's batch placeholders so we get
        // efficient batched embedder calls.
        $embedder = [
            'source' => 'rest',
            // Infomaniak's OpenAI-compatible embeddings endpoint sits at
            // /openai/v1/embeddings — the v1 prefix is required (without it
            // the API responds with HTTP 200 + method_not_found). Note the
            // model names use underscores (bge_multilingual_gemma2,
            // mini_lm_l12_v2), not the dashed style Infomaniak advertises in
            // its docs for the chat endpoint.
            'url' => 'https://api.infomaniak.com/1/ai/' . rawurlencode($productId) . '/openai/v1/embeddings',
            // Single-text-per-request, NOT the batch `['{{text}}', '{{..}}']`
            // form. Reason: Infomaniak rejects batches of ≥100 items with
            // HTTP 422 (`The input list must have less than 100 items`), and
            // Meilisearch chooses its own batch size with no documented knob
            // to cap it. Empirically, large indexes with bge_multilingual_gemma2
            // pegged the batch above 100 and the entire settingsUpdate stuck
            // in retry-loop hell — task `processing` for 30+ min without a
            // single vector landing. The single-text form is slower per call
            // (1 HTTP roundtrip per doc instead of ~50) but bounded and
            // predictable; Meilisearch fans them out in parallel anyway.
            'request' => [
                'model' => $model,
                'input' => '{{text}}',
            ],
            'response' => [
                'data' => [
                    ['embedding' => '{{embedding}}'],
                ],
            ],
        ];
        foreach (['apiKey', 'documentTemplate'] as $field) {
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
     * Scaleway Generative APIs preset. OpenAI-compatible embeddings
     * endpoint at https://api.scaleway.ai/v1/embeddings, single global
     * URL (no tenant interpolation needed — auth happens via the API
     * key in the bearer header).
     *
     * Model names use the standard dashed form: `bge-multilingual-gemma2`,
     * `qwen3-embedding-8b`. Tested with `bge-multilingual-gemma2` at 200
     * parallel embedding requests/9s → 0 failures, vs Infomaniaks 60/min
     * cap that broke the 50k-doc reindex.
     *
     * @return array<string,array<string,mixed>>
     */
    private function buildScalewayEmbedder(Site $site): array
    {
        $settings = $site->getSettings();
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));
        if ($model === '' || $apiKey === '') {
            $this->logger?->warning(
                'Scaleway embedder preset requires meilisearch.embedder.model AND meilisearch.embedder.apiKey for site {id} — skipping embedder push',
                ['id' => $site->getIdentifier()],
            );
            return [];
        }
        $embedder = [
            'source' => 'rest',
            'url' => 'https://api.scaleway.ai/v1/embeddings',
            'apiKey' => $apiKey,
            // Single-text-per-request rather than the array+repeater
            // batch form — same rationale as Infomaniak: Meilisearch
            // picks its own batch size with no documented cap, so we
            // stay on the predictable one-call-per-doc path. Even at
            // that rate Scaleway sustained 22 req/s in the burst test
            // without rejecting a single call.
            'request' => [
                'model' => $model,
                'input' => '{{text}}',
            ],
            'response' => [
                'data' => [
                    ['embedding' => '{{embedding}}'],
                ],
            ],
        ];
        $template = trim((string)$settings->get('meilisearch.embedder.documentTemplate', ''));
        if ($template !== '') {
            $embedder['documentTemplate'] = $template;
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
        // `request`/`response` belong to the `rest` source and contain nested
        // arrays — flattened via json_encode so they survive comparison.
        $allowed = ['source', 'model', 'url', 'dimensions', 'documentTemplate', 'request', 'response'];
        $arrayKeys = ['request', 'response'];
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
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                if (in_array($key, $arrayKeys, true) && is_array($value)) {
                    $filtered[$key] = (string)json_encode($value);
                } elseif ($key === 'dimensions') {
                    $filtered[$key] = (int)$value;
                } else {
                    $filtered[$key] = (string)$value;
                }
            }
            ksort($filtered);
            $out[(string)$name] = $filtered;
        }
        ksort($out);
        return $out;
    }
}
<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Fetches embeddings from the configured provider before a document is
 * pushed to Meilisearch, so Meilisearch itself never calls the embedding
 * provider. The embedder on the index is configured as `userProvided`
 * with the right `dimensions`; documents arrive with their vector
 * pre-attached in `_vectors.default = [...]`.
 *
 * Why: Meilisearch auto-batches enqueued documentAdditionOrUpdate tasks
 * and fans the embedding calls out in parallel. There is no documented
 * knob to cap that fan-out. On rate-limited providers (Scaleway's
 * "requests per minute" quota, Infomaniak's 60 RPM hard cap) the
 * majority of tasks fail with vector_embedding_error / HTTP 429.
 *
 * By moving the embedding step into PHP, we own the call rate and can
 * enforce a strict token-bucket against the provider — Meilisearch
 * sees pre-computed vectors and skips its own embedder pipeline.
 *
 * Activation: meilisearch.embedder.precompute = true (per site).
 * Throttle:   meilisearch.indexing.requestsPerMinute = N (token bucket).
 *
 * Currently supports the same provider set as the REST embedder presets:
 *   - scaleway   → https://api.scaleway.ai/v1/embeddings
 *   - infomaniak → derived from infomaniak.productId
 *   - openAi     → standard OpenAI /v1/embeddings (or `meilisearch.embedder.url`)
 */
final class EmbeddingPrecomputer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const VECTOR_FIELD = '_vectors';

    /**
     * Last embedding-request wall clock per site (microseconds), used by
     * the throttle to enforce a minimum interval between calls. Indexed
     * by site identifier so multi-site reindex runs don't share state.
     *
     * @var array<string,int>
     */
    private array $lastCallUs = [];

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * True when the site is configured to precompute embeddings in PHP
     * instead of letting Meilisearch call the provider itself.
     */
    public function isEnabledForSite(Site $site): bool
    {
        $settings = $site->getSettings();
        if ((bool)$settings->get('meilisearch.embedder.precompute', false) !== true) {
            return false;
        }
        $source = trim((string)$settings->get('meilisearch.embedder.source', ''));
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));
        $dims = (int)$settings->get('meilisearch.embedder.dimensions', 0);
        return $source !== '' && $model !== '' && $apiKey !== '' && $dims > 0;
    }

    /**
     * Attach the precomputed vector to the document under
     * `_vectors.default = [...]`. Returns the document unchanged when
     * embedding fails (logged), so the document still lands in the index
     * for keyword search even if the semantic component is missing.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function attachEmbedding(Site $site, array $document): array
    {
        $settings = $site->getSettings();
        $text = $this->renderTemplate(
            trim((string)$settings->get('meilisearch.embedder.documentTemplate', '')),
            $document,
        );
        if ($text === '') {
            // Empty text → embedding API would return either an error or
            // a zero vector; skip and let the doc index keyword-only.
            return $document;
        }
        $this->throttle($site);
        try {
            $vector = $this->fetchEmbedding($site, $text);
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Embedding precompute failed for document {id} on site {site}: {msg}',
                [
                    'id' => (string)($document['id'] ?? '?'),
                    'site' => $site->getIdentifier(),
                    'msg' => $e->getMessage(),
                ],
            );
            return $document;
        }
        $document[self::VECTOR_FIELD] = ($document[self::VECTOR_FIELD] ?? [])
            + [EmbedderConfigurator::EMBEDDER_NAME => $vector];
        return $document;
    }

    /**
     * Token-bucket-style minimum interval guard. Uses the same
     * `meilisearch.indexing.requestsPerMinute` setting that the
     * IndexerService consults — single source of truth for the cap.
     */
    private function throttle(Site $site): void
    {
        $rpm = (int)$site->getSettings()->get('meilisearch.indexing.requestsPerMinute', 0);
        if ($rpm <= 0) {
            return;
        }
        $minIntervalUs = (int)(60_000_000 / $rpm);
        $key = $site->getIdentifier();
        $nowUs = (int)(microtime(true) * 1_000_000);
        $waitUs = $minIntervalUs - ($nowUs - ($this->lastCallUs[$key] ?? 0));
        if ($waitUs > 0) {
            usleep($waitUs);
        }
        $this->lastCallUs[$key] = (int)(microtime(true) * 1_000_000);
    }

    /**
     * @return list<float>
     */
    private function fetchEmbedding(Site $site, string $text): array
    {
        $settings = $site->getSettings();
        $source = trim((string)$settings->get('meilisearch.embedder.source', ''));
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));

        $url = match ($source) {
            'scaleway' => 'https://api.scaleway.ai/v1/embeddings',
            'infomaniak' => 'https://api.infomaniak.com/1/ai/'
                . rawurlencode((string)$settings->get('meilisearch.infomaniak.productId', ''))
                . '/openai/v1/embeddings',
            // openAi / rest / unknown — let an explicit URL override win, otherwise default to OpenAI.
            default => trim((string)$settings->get('meilisearch.embedder.url', '')) ?: 'https://api.openai.com/v1/embeddings',
        };

        $response = $this->requestFactory->request(
            $url,
            'POST',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(
                    ['model' => $model, 'input' => $text],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
                'timeout' => 60,
            ],
        );
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException(sprintf(
                'Embedding HTTP %d for site %s: %s',
                $status,
                $site->getIdentifier(),
                substr((string)$response->getBody(), 0, 300),
            ));
        }
        $payload = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        $vector = $payload['data'][0]['embedding'] ?? null;
        if (!is_array($vector) || $vector === []) {
            throw new \RuntimeException('Embedding response missing data[0].embedding');
        }
        return array_map(static fn($v) => (float)$v, array_values($vector));
    }

    /**
     * Resolve `{{ doc.field }}` placeholders in the documentTemplate
     * against the document being pushed. Falls back to title+content
     * when no template is configured. Whitespace around the field name
     * is tolerated to match Meilisearch's own Liquid-style accepting
     * `{{ doc.title }}` and `{{doc.title}}` equally.
     *
     * @param array<string,mixed> $document
     */
    private function renderTemplate(string $template, array $document): string
    {
        if ($template === '') {
            $title = trim((string)($document['title'] ?? ''));
            $content = trim((string)($document['content'] ?? ($document['bodytext'] ?? '')));
            return trim($title . ($title !== '' && $content !== '' ? '. ' : '') . $content);
        }
        $rendered = preg_replace_callback(
            '/\{\{\s*doc\.([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $m) use ($document) {
                $value = $document[$m[1]] ?? '';
                return is_scalar($value) ? (string)$value : '';
            },
            $template,
        );
        return trim((string)$rendered);
    }
}

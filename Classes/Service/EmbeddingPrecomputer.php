<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Indexing\EmbeddingFailedException;
use WapplerSystems\Meilisearch\Service\Indexing\RetryableEmbeddingError;

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
 * enforce a strict budget against the provider — Meilisearch sees
 * pre-computed vectors and skips its own embedder pipeline.
 *
 * Activation: meilisearch.embedder.precompute = true (per site).
 *
 * Rate control (all per site, all optional):
 *   meilisearch.indexing.requestsPerMinute  — calls/min (min-interval guard)
 *   meilisearch.indexing.tokensPerMinute    — *estimated* tokens/min, sliding
 *                                             60s window. This is the limit
 *                                             that actually bites on Scaleway:
 *                                             "INSUFFICIENT QUOTA … quota
 *                                             tokens per minute. Slow down".
 *                                             A requests/min cap does nothing
 *                                             against it, because a handful of
 *                                             full page texts can exhaust the
 *                                             token budget in one call.
 *   meilisearch.indexing.embedBatchSize     — inputs per provider request
 *   meilisearch.embedder.maxInputChars      — hard truncation per document
 *   meilisearch.embedder.maxRetries         — attempts per request (429/5xx)
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
     * Default cap on the text handed to the embedding provider. ~8000
     * characters is roughly 2000 tokens for German prose — well inside
     * every supported model's context window, and it stops a single
     * 400-page PDF from eating a whole minute's token budget on its own.
     * Long documents lose nothing that matters for retrieval: the tail of
     * a document contributes almost nothing to a mean-pooled vector, and
     * keyword search still covers the full text.
     */
    private const DEFAULT_MAX_INPUT_CHARS = 8000;

    /** Characters per token used for the pre-flight token estimate. */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Last embedding-request wall clock per site (microseconds), used by
     * the throttle to enforce a minimum interval between calls. Indexed
     * by site identifier so multi-site reindex runs don't share state.
     *
     * @var array<string,int>
     */
    private array $lastCallUs = [];

    /**
     * Sliding one-minute window of estimated token spend per site:
     * list of [timestampUs, tokens]. Pruned on every reservation.
     *
     * @var array<string,list<array{0:int,1:int}>>
     */
    private array $tokenWindow = [];

    /**
     * What the provider itself last said about the remaining budget,
     * parsed from its `x-ratelimit-*` response headers. Far better than
     * our own chars/4 estimate: Scaleway reports the real limit (300
     * requests and 200.000 tokens per minute for bge-multilingual-gemma2)
     * plus how much of it is left and when it resets. When the provider
     * tells us, we stop guessing.
     *
     * @var array<string,array{limit:int,remaining:int,resetAtUs:int}>
     */
    private array $providerBudget = [];

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
     * Vector length the index expects. Used to sanity-check vectors that
     * are re-used from an existing document instead of re-embedded — a
     * mismatch means the embedder was swapped and the old vector is
     * unusable.
     */
    public function getDimensions(Site $site): int
    {
        return (int)$site->getSettings()->get('meilisearch.embedder.dimensions', 0);
    }

    /**
     * How many inputs to send per provider request. The OpenAI-compatible
     * embeddings API (Scaleway, Infomaniak, OpenAI) accepts an array in
     * `input` and answers with one entry per element, so batching cuts the
     * request count by this factor without changing the token spend.
     */
    public function getBatchSize(Site $site): int
    {
        $size = (int)$site->getSettings()->get('meilisearch.indexing.embedBatchSize', 8);
        return max(1, min(64, $size));
    }

    /**
     * The exact text that goes to the embedding provider for a document:
     * documentTemplate rendered against the document, then truncated.
     *
     * @param array<string,mixed> $document
     */
    public function buildEmbedText(Site $site, array $document): string
    {
        $text = $this->renderTemplate(
            trim((string)$site->getSettings()->get('meilisearch.embedder.documentTemplate', '')),
            $document,
        );
        $max = $this->maxInputChars($site);
        if ($max > 0 && mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }
        return $text;
    }

    /**
     * Fingerprint of "what this document's vector was computed from".
     *
     * Deliberately covers more than the text: model, dimensions and the
     * truncation limit go in too, so that swapping the embedder model or
     * widening maxInputChars automatically invalidates every cached vector
     * instead of leaving a silently mixed-provenance index behind.
     */
    public function embedHashFor(Site $site, string $text): string
    {
        if ($text === '') {
            return '';
        }
        $settings = $site->getSettings();
        return hash('xxh128', implode("\0", [
            trim((string)$settings->get('meilisearch.embedder.source', '')),
            trim((string)$settings->get('meilisearch.embedder.model', '')),
            (string)$this->getDimensions($site),
            (string)$this->maxInputChars($site),
            $text,
        ]));
    }

    /**
     * Attach the precomputed vector to the document under
     * `_vectors.default = [...]`.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     * @throws EmbeddingFailedException when the provider never delivered a vector
     */
    public function attachEmbedding(Site $site, array $document): array
    {
        $text = $this->buildEmbedText($site, $document);
        if ($text === '') {
            // Nothing to embed. Meilisearch would reject the document
            // against the userProvided embedder, so this is a failure —
            // just a deterministic one that retrying cannot fix.
            throw new EmbeddingFailedException(sprintf(
                'Document %s on site %s renders an empty embedder template — nothing to embed',
                (string)($document['id'] ?? '?'),
                $site->getIdentifier(),
            ));
        }
        $vectors = $this->embedTexts($site, [$text]);
        return $this->withVector($document, $vectors[0]);
    }

    /**
     * Write a vector into a document in the shape Meilisearch expects for
     * a `userProvided` embedder. Kept in one place so the reuse path
     * (vector copied from an existing document) and the fresh-embedding
     * path produce byte-identical documents.
     *
     * @param array<string,mixed> $document
     * @param list<float> $vector
     * @return array<string,mixed>
     */
    public function withVector(array $document, array $vector): array
    {
        $document[self::VECTOR_FIELD] = ($document[self::VECTOR_FIELD] ?? [])
            + [EmbedderConfigurator::EMBEDDER_NAME => $vector];
        return $document;
    }

    /**
     * Embed a batch of texts in as few provider requests as the configured
     * batch size allows. Keys of the result match the keys of $texts.
     *
     * @param array<int|string,string> $texts
     * @return array<int|string,list<float>>
     * @throws EmbeddingFailedException
     */
    public function embedTexts(Site $site, array $texts): array
    {
        if ($texts === []) {
            return [];
        }
        $result = [];
        $batchSize = $this->getBatchSize($site);
        foreach (array_chunk($texts, $batchSize, true) as $chunk) {
            $keys = array_keys($chunk);
            $vectors = $this->requestEmbeddings($site, array_values($chunk));
            if (count($vectors) !== count($keys)) {
                throw new EmbeddingFailedException(sprintf(
                    'Embedding provider returned %d vectors for %d inputs on site %s',
                    count($vectors),
                    count($keys),
                    $site->getIdentifier(),
                ));
            }
            foreach ($keys as $position => $key) {
                $result[$key] = $vectors[$position];
            }
        }
        return $result;
    }

    /**
     * One provider call for up to batchSize inputs, with rate reservation
     * and retries. Retries cover HTTP 429 (quota / rate limit) and 5xx
     * (provider hiccup); a 4xx other than 429 is a request-shape problem
     * that retrying cannot fix, so it fails immediately.
     *
     * @param list<string> $inputs
     * @return list<list<float>>
     * @throws EmbeddingFailedException
     */
    private function requestEmbeddings(Site $site, array $inputs): array
    {
        $maxAttempts = max(1, (int)$site->getSettings()->get('meilisearch.embedder.maxRetries', 5));
        $estimatedTokens = 0;
        foreach ($inputs as $input) {
            $estimatedTokens += (int)ceil(mb_strlen($input) / self::CHARS_PER_TOKEN);
        }

        $lastError = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->reserveTokens($site, $estimatedTokens);
            $this->awaitProviderBudget($site, $estimatedTokens);
            $this->throttle($site);
            try {
                return $this->postEmbeddings($site, $inputs);
            } catch (RetryableEmbeddingError $e) {
                $lastError = $e->getMessage();
                if ($attempt === $maxAttempts) {
                    break;
                }
                $waitSeconds = $e->retryAfterSeconds ?? $this->backoffSeconds($attempt);
                $this->logger?->warning(
                    'Embedding provider throttled on site {site} (attempt {attempt}/{max}): {msg} — retrying in {wait}s',
                    [
                        'site' => $site->getIdentifier(),
                        'attempt' => $attempt,
                        'max' => $maxAttempts,
                        'msg' => $e->getMessage(),
                        'wait' => $waitSeconds,
                    ],
                );
                // A 429 means our own estimate of the provider's budget was
                // too optimistic. Burn the rest of the current window so the
                // next attempt starts from a clean slate instead of hammering
                // straight back into the same limit.
                $this->tokenWindow[$site->getIdentifier()] = [];
                usleep((int)round($waitSeconds * 1_000_000));
            } catch (\Throwable $e) {
                throw new EmbeddingFailedException(
                    sprintf('Embedding failed on site %s: %s', $site->getIdentifier(), $e->getMessage()),
                    0,
                    $e,
                );
            }
        }

        throw new EmbeddingFailedException(sprintf(
            'Embedding gave up after %d attempts on site %s: %s',
            $maxAttempts,
            $site->getIdentifier(),
            $lastError,
        ));
    }

    /**
     * Exponential backoff with jitter, capped at a minute. Used when the
     * provider gives no Retry-After header.
     */
    private function backoffSeconds(int $attempt): float
    {
        $base = min(60, 2 ** $attempt);
        return $base + random_int(0, 1000) / 1000;
    }

    /**
     * Minimum-interval guard against `meilisearch.indexing.requestsPerMinute`.
     * Counts REQUESTS — with batching one request now carries several
     * documents, so this alone is no longer a meaningful quota guard; see
     * reserveTokens() for the one that is.
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
     * Sliding-window token budget against
     * `meilisearch.indexing.tokensPerMinute`. Sleeps until the request
     * fits into the remaining budget of the last 60 seconds.
     *
     * The estimate (chars/4) is intentionally rough — it does not have to
     * match the provider's tokenizer, it only has to keep us under the
     * cliff. Whenever the provider disagrees and answers 429, the retry
     * path clears the window and backs off, which corrects any drift.
     */
    private function reserveTokens(Site $site, int $tokens): void
    {
        $tpm = (int)$site->getSettings()->get('meilisearch.indexing.tokensPerMinute', 0);
        if ($tpm <= 0) {
            // Nothing configured — fall back to the limit the provider
            // reported on the previous call, so a fresh installation is
            // throttled correctly without anyone having to look the
            // number up.
            $tpm = $this->providerBudget[$site->getIdentifier()]['limit'] ?? 0;
        }
        if ($tpm <= 0 || $tokens <= 0) {
            return;
        }
        $key = $site->getIdentifier();
        $windowUs = 60_000_000;

        while (true) {
            $nowUs = (int)(microtime(true) * 1_000_000);
            $window = array_values(array_filter(
                $this->tokenWindow[$key] ?? [],
                static fn(array $entry): bool => $entry[0] > $nowUs - $windowUs,
            ));
            $this->tokenWindow[$key] = $window;

            $spent = array_sum(array_column($window, 1));
            if ($spent + $tokens <= $tpm || $window === []) {
                // Second condition: a single request larger than the whole
                // per-minute budget can never fit. Let it through on an
                // empty window rather than spinning forever — the provider
                // will 429 and the retry path handles it.
                break;
            }
            // Sleep until the oldest entry falls out of the window.
            $sleepUs = ($window[0][0] + $windowUs) - $nowUs;
            usleep(max(50_000, $sleepUs));
        }

        $this->tokenWindow[$key][] = [(int)(microtime(true) * 1_000_000), $tokens];
    }

    /**
     * @param list<string> $inputs
     * @return list<list<float>>
     * @throws RetryableEmbeddingError
     */
    private function postEmbeddings(Site $site, array $inputs): array
    {
        $settings = $site->getSettings();
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));

        $response = $this->requestFactory->request(
            $this->resolveEndpoint($site),
            'POST',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(
                    // Single-element batches keep the scalar form: some
                    // OpenAI-compatible gateways answer differently for a
                    // one-element array, and the scalar form is what this
                    // integration has always sent.
                    ['model' => $model, 'input' => count($inputs) === 1 ? $inputs[0] : $inputs],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
                'timeout' => 120,
                // Read the status ourselves — a 429 must be inspected for
                // its Retry-After header, not thrown away as an exception.
                'http_errors' => false,
            ],
        );

        $status = $response->getStatusCode();
        if ($status === 429 || $status >= 500) {
            $retryAfter = $this->parseRetryAfter($response->getHeaderLine('Retry-After'));
            throw new RetryableEmbeddingError(
                sprintf('HTTP %d: %s', $status, substr((string)$response->getBody(), 0, 300)),
                $retryAfter,
            );
        }
        if ($status !== 200) {
            throw new \RuntimeException(sprintf(
                'Embedding HTTP %d for site %s: %s',
                $status,
                $site->getIdentifier(),
                substr((string)$response->getBody(), 0, 300),
            ));
        }

        $this->recordProviderBudget($site, $response);

        $payload = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        $data = $payload['data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new \RuntimeException('Embedding response missing data[]');
        }
        // The API is allowed to return the entries out of order; `index`
        // carries the position in the request.
        $vectors = [];
        foreach ($data as $position => $entry) {
            $vector = $entry['embedding'] ?? null;
            if (!is_array($vector) || $vector === []) {
                throw new \RuntimeException('Embedding response entry missing `embedding`');
            }
            $index = isset($entry['index']) ? (int)$entry['index'] : (int)$position;
            $vectors[$index] = array_map(static fn($v): float => (float)$v, array_values($vector));
        }
        ksort($vectors);
        return array_values($vectors);
    }

    /**
     * Remember what the provider reported about its token budget.
     * OpenAI-compatible gateways (Scaleway among them) answer with
     * `x-ratelimit-limit-tokens`, `x-ratelimit-remaining-tokens` and
     * `x-ratelimit-reset-tokens`.
     */
    private function recordProviderBudget(Site $site, \Psr\Http\Message\ResponseInterface $response): void
    {
        $limit = $response->getHeaderLine('x-ratelimit-limit-tokens');
        $remaining = $response->getHeaderLine('x-ratelimit-remaining-tokens');
        if ($limit === '' && $remaining === '') {
            return;
        }
        $resetUs = $this->parseDurationToMicroseconds($response->getHeaderLine('x-ratelimit-reset-tokens'));
        $key = $site->getIdentifier();
        $this->providerBudget[$key] = [
            'limit' => is_numeric($limit) ? (int)$limit : ($this->providerBudget[$key]['limit'] ?? 0),
            'remaining' => is_numeric($remaining) ? (int)$remaining : 0,
            'resetAtUs' => (int)(microtime(true) * 1_000_000) + $resetUs,
        ];
    }

    /**
     * Wait out the provider's own reset window when its reported
     * remaining budget cannot cover the next request. This is the
     * accurate half of the rate control — the chars/4 estimate only has
     * to carry us until the first response comes back.
     */
    private function awaitProviderBudget(Site $site, int $tokens): void
    {
        $budget = $this->providerBudget[$site->getIdentifier()] ?? null;
        if ($budget === null || $budget['limit'] <= 0) {
            return;
        }
        if ($budget['remaining'] >= $tokens) {
            return;
        }
        $waitUs = $budget['resetAtUs'] - (int)(microtime(true) * 1_000_000);
        if ($waitUs <= 0) {
            return;
        }
        // Never sleep longer than the window the limit is expressed in;
        // a bogus header must not park an indexing run for hours.
        usleep(min($waitUs, 60_000_000));
    }

    /**
     * `x-ratelimit-reset-*` is a Go-style duration: "0ms", "200ms",
     * "1.5s", "2m3s". Returns microseconds, 0 when unparseable.
     */
    private function parseDurationToMicroseconds(string $duration): int
    {
        $duration = trim($duration);
        if ($duration === '') {
            return 0;
        }
        if (is_numeric($duration)) {
            // Bare number: seconds, as in the OpenAI spec.
            return (int)round((float)$duration * 1_000_000);
        }
        $total = 0.0;
        if (preg_match_all('/([0-9]*\.?[0-9]+)(ms|us|s|m|h)/', $duration, $matches, PREG_SET_ORDER) === 0) {
            return 0;
        }
        foreach ($matches as $match) {
            $value = (float)$match[1];
            $total += match ($match[2]) {
                'us' => $value,
                'ms' => $value * 1_000,
                's' => $value * 1_000_000,
                'm' => $value * 60_000_000,
                'h' => $value * 3_600_000_000,
            };
        }
        return (int)round($total);
    }

    /**
     * Retry-After is either delay-seconds or an HTTP-date. Returns null
     * when absent or unparseable, letting the caller fall back to its own
     * backoff.
     */
    private function parseRetryAfter(string $header): ?float
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (is_numeric($header)) {
            return max(0.0, min(300.0, (float)$header));
        }
        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }
        return max(0.0, min(300.0, (float)($timestamp - time())));
    }

    private function resolveEndpoint(Site $site): string
    {
        $settings = $site->getSettings();
        $source = trim((string)$settings->get('meilisearch.embedder.source', ''));

        return match ($source) {
            'scaleway' => 'https://api.scaleway.ai/v1/embeddings',
            'infomaniak' => 'https://api.infomaniak.com/1/ai/'
                . rawurlencode((string)$settings->get('meilisearch.infomaniak.productId', ''))
                . '/openai/v1/embeddings',
            // openAi / rest / unknown — let an explicit URL override win, otherwise default to OpenAI.
            default => trim((string)$settings->get('meilisearch.embedder.url', '')) ?: 'https://api.openai.com/v1/embeddings',
        };
    }

    private function maxInputChars(Site $site): int
    {
        $configured = $site->getSettings()->get('meilisearch.embedder.maxInputChars', null);
        if ($configured === null || $configured === '') {
            return self::DEFAULT_MAX_INPUT_CHARS;
        }
        return max(0, (int)$configured);
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

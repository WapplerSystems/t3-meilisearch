<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * OpenAI `/v1/embeddings` API client. Reads meilisearch.embedder.model
 * + meilisearch.embedder.apiKey + meilisearch.embedder.url from site
 * settings; defaults the URL to https://api.openai.com/v1/embeddings
 * since that's the canonical endpoint.
 *
 * Selected when meilisearch.embedder.source === 'openAi'. Same models
 * the Meilisearch openAi-source embedder uses for hybrid search
 * (text-embedding-3-small, ada-002, …) — keeps similarity-scoring
 * consistent with whatever's already indexed.
 */
final class OpenAiEmbeddingClient implements BatchEmbeddingClientInterface
{
    private const DEFAULT_URL = 'https://api.openai.com/v1/embeddings';

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function supports(string $sourceName): bool
    {
        return strtolower($sourceName) === 'openai';
    }

    public function embed(Site $site, string $text): array
    {
        $settings = $site->getSettings();
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));
        if ($model === '' || $apiKey === '') {
            throw new \RuntimeException('OpenAI embedder not configured (meilisearch.embedder.model / apiKey missing)');
        }
        $url = trim((string)$settings->get('meilisearch.embedder.url', '')) ?: self::DEFAULT_URL;

        return extractOpenAiEmbedding($this->requestFactory, $url, $apiKey, $model, $text);
    }

    public function embedMany(Site $site, array $texts): array
    {
        $settings = $site->getSettings();
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));
        if ($model === '' || $apiKey === '') {
            throw new \RuntimeException('OpenAI embedder not configured (meilisearch.embedder.model / apiKey missing)');
        }
        $url = trim((string)$settings->get('meilisearch.embedder.url', '')) ?: self::DEFAULT_URL;
        return extractOpenAiEmbeddings($this->requestFactory, $url, $apiKey, $model, $texts);
    }
}

/**
 * Several texts in one call: the OpenAI embeddings API takes an `input`
 * array and answers with one `data` entry per text, each carrying its index.
 *
 * @param list<string> $texts
 * @return list<list<float>>
 */
function extractOpenAiEmbeddings(RequestFactory $requestFactory, string $url, string $apiKey, string $model, array $texts): array
{
    $response = $requestFactory->request($url, 'POST', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
        'body' => (string)json_encode(['input' => array_values($texts), 'model' => $model]),
    ]);
    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
        throw new \RuntimeException(sprintf('OpenAI-compatible embeddings returned HTTP %d at %s', $status, $url));
    }
    $payload = json_decode((string)$response->getBody(), true);
    $vectors = [];
    foreach ((array)($payload['data'] ?? []) as $position => $entry) {
        if (!is_array($entry) || !is_array($entry['embedding'] ?? null)) {
            continue;
        }
        $vectors[(int)($entry['index'] ?? $position)] = array_map(static fn ($v): float => (float)$v, $entry['embedding']);
    }
    ksort($vectors);
    if (count($vectors) !== count($texts)) {
        throw new \RuntimeException(sprintf('OpenAI-compatible response has %d embeddings for %d inputs', count($vectors), count($texts)));
    }

    return array_values($vectors);
}

/**
 * Shared OpenAI-compatible embedding call. Lives at file scope so both
 * the OpenAI and Infomaniak clients call exactly the same code path —
 * extracting it into an abstract base would force a class-name change
 * on every consumer of the Embedding interface.
 *
 * @return list<float>
 */
function extractOpenAiEmbedding(RequestFactory $requestFactory, string $url, string $apiKey, string $model, string $text): array
{
    $response = $requestFactory->request($url, 'POST', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ],
        'body' => (string)json_encode(['input' => $text, 'model' => $model]),
    ]);
    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
        throw new \RuntimeException(sprintf('OpenAI-compatible embeddings returned HTTP %d at %s', $status, $url));
    }
    $payload = json_decode((string)$response->getBody(), true);
    if (!is_array($payload) || empty($payload['data'][0]['embedding']) || !is_array($payload['data'][0]['embedding'])) {
        throw new \RuntimeException('OpenAI-compatible response missing "data[0].embedding" array');
    }
    return array_map(static fn ($v): float => (float)$v, $payload['data'][0]['embedding']);
}

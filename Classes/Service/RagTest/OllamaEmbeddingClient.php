<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Ollama-backed embeddings via its native `/api/embeddings` endpoint
 * (NOT the OpenAI-compatible `/v1/embeddings` route). Reads
 * meilisearch.embedder.url + meilisearch.embedder.model from the
 * site settings, normalises the URL (operators sometimes put the
 * full endpoint URL there, sometimes just the host).
 *
 * Why a dedicated client and not a generic /v1/embeddings call:
 * the EmbedderConfigurator already targets `/api/embeddings` for
 * Ollama because Meilisearch's ollama source talks the native
 * protocol. Reusing the same endpoint keeps the model and host
 * configuration shared with what's already running for hybrid search.
 */
final class OllamaEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function supports(string $sourceName): bool
    {
        return strtolower($sourceName) === 'ollama';
    }

    public function embed(Site $site, string $text): array
    {
        $settings = $site->getSettings();
        $url = trim((string)$settings->get('meilisearch.embedder.url', ''));
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        if ($url === '' || $model === '') {
            throw new \RuntimeException('Ollama embedder not configured (meilisearch.embedder.url / model missing)');
        }
        $endpoint = $this->normaliseEndpoint($url);

        $response = $this->requestFactory->request($endpoint, 'POST', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string)json_encode(['model' => $model, 'prompt' => $text]),
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Ollama embeddings returned HTTP %d', $status));
        }
        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload) || !isset($payload['embedding']) || !is_array($payload['embedding'])) {
            throw new \RuntimeException('Ollama response missing "embedding" array');
        }
        return array_map(static fn ($v): float => (float)$v, $payload['embedding']);
    }

    /**
     * Operators put any of these in meilisearch.embedder.url:
     *   http://ollama:11434
     *   http://ollama:11434/
     *   http://ollama:11434/api/embeddings
     *   http://ollama:11434/v1/embeddings  (OpenAI-style — wrong for us)
     * Coerce all of them to the native /api/embeddings endpoint.
     */
    private function normaliseEndpoint(string $url): string
    {
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/api/embeddings')) {
            return $url;
        }
        // Strip OpenAI-style suffix; we want the native one.
        if (str_ends_with($url, '/v1/embeddings')) {
            $url = substr($url, 0, -strlen('/v1/embeddings'));
        } elseif (str_ends_with($url, '/v1')) {
            $url = substr($url, 0, -3);
        }
        return rtrim($url, '/') . '/api/embeddings';
    }
}

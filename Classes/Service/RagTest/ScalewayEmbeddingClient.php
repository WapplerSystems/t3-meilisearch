<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Scaleway Generative APIs embeddings — OpenAI-compatible endpoint at
 * https://api.scaleway.ai/v1/embeddings. Reads meilisearch.embedder.model
 * + meilisearch.embedder.apiKey from site settings; URL is fixed (no
 * tenant interpolation — Scaleway authenticates via the bearer token).
 *
 * Selected when meilisearch.embedder.source === 'scaleway'. Used by the
 * RAG regression-test scorer to embed the candidate answer + the
 * expected answer under the same model the live index is using, so
 * cosine similarity stays meaningful.
 */
final class ScalewayEmbeddingClient implements EmbeddingClientInterface
{
    private const URL = 'https://api.scaleway.ai/v1/embeddings';

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function supports(string $sourceName): bool
    {
        return strtolower($sourceName) === 'scaleway';
    }

    public function embed(Site $site, string $text): array
    {
        $settings = $site->getSettings();
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));
        if ($model === '' || $apiKey === '') {
            throw new \RuntimeException('Scaleway embedder not configured (meilisearch.embedder.model / embedder.apiKey missing)');
        }
        return extractOpenAiEmbedding($this->requestFactory, self::URL, $apiKey, $model, $text);
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Infomaniak AI Tools embeddings — OpenAI-compatible API at a
 * product-id-bound URL. Reads meilisearch.embedder.model +
 * meilisearch.embedder.apiKey + meilisearch.infomaniak.productId from
 * site settings; the URL is built from productId since Infomaniak's
 * endpoint shape is fixed by their /openai/v1/embeddings convention.
 *
 * Selected when meilisearch.embedder.source === 'infomaniak'. Model
 * names use underscore style (bge_multilingual_gemma2, mini_lm_l12_v2)
 * — Infomaniak's docs occasionally show the dashed style for chat
 * endpoints; the embedder endpoint wants underscores.
 *
 * Why a sibling client and not just "openAi with a different URL":
 * the URL is built from a separate site setting (productId), not from
 * meilisearch.embedder.url, so dispatch needs to recognise the source
 * slug to know which URL convention to apply.
 */
final class InfomaniakEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function supports(string $sourceName): bool
    {
        return strtolower($sourceName) === 'infomaniak';
    }

    public function embed(Site $site, string $text): array
    {
        $settings = $site->getSettings();
        $productId = trim((string)$settings->get('meilisearch.infomaniak.productId', ''));
        $model = trim((string)$settings->get('meilisearch.embedder.model', ''));
        $apiKey = trim((string)$settings->get('meilisearch.embedder.apiKey', ''));
        if ($productId === '' || $model === '' || $apiKey === '') {
            throw new \RuntimeException('Infomaniak embedder not configured (meilisearch.infomaniak.productId / embedder.model / embedder.apiKey missing)');
        }
        $url = 'https://api.infomaniak.com/1/ai/' . rawurlencode($productId) . '/openai/v1/embeddings';

        return extractOpenAiEmbedding($this->requestFactory, $url, $apiKey, $model, $text);
    }
}

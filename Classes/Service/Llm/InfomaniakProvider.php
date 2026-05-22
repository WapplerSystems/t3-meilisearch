<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

/**
 * Infomaniak AI Tools provider — OpenAI-compatible endpoint hosted in
 * Switzerland (sovereign cloud). Same wire format as OpenAI, two practical
 * differences vs. the parent provider:
 *  - the path is `/openai/chat/completions` (no `/v1` segment)
 *  - the base URL embeds a per-account product id, derived here from the
 *    `productId` option rather than the user manually composing a URL
 *
 * Settings users fill:
 *  - meilisearch.rag.provider     = infomaniak
 *  - meilisearch.infomaniak.productId = 12345        (Infomaniak account)
 *  - meilisearch.rag.apiKey       = <bearer token>
 *  - meilisearch.rag.model        = mistralai/Mistral-Small-4-119B-2603
 *
 * An explicit `meilisearch.rag.url` still wins if provided — useful for
 * staging endpoints or when Infomaniak changes the base path.
 */
final class InfomaniakProvider extends OpenAiProvider
{
    protected const DEFAULT_BASE_URL = 'https://api.infomaniak.com';
    protected const CHAT_PATH = '/chat/completions';

    public function name(): string
    {
        return 'infomaniak';
    }

    protected function resolveBaseUrl(array $options): string
    {
        $explicit = trim((string)($options['url'] ?? ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }
        $productId = trim((string)($options['productId'] ?? ''));
        if ($productId === '') {
            throw new LlmException('Infomaniak: productId (meilisearch.infomaniak.productId) is required when no url is configured');
        }
        return 'https://api.infomaniak.com/1/ai/' . rawurlencode($productId) . '/openai';
    }
}

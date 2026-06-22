<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

/**
 * Scaleway Generative APIs provider — OpenAI-compatible endpoint hosted in
 * Paris (sovereign EU cloud). Identical wire format to OpenAI, only the
 * base URL differs.
 *
 * Why a separate provider class instead of pointing the generic openAi
 * source at Scaleway's URL? Naming + discoverability: integrators select
 * the provider by `meilisearch.rag.provider = scaleway` rather than
 * remembering to set the right `meilisearch.rag.url`. Same pattern as
 * `infomaniak`.
 *
 * Settings users fill:
 *  - meilisearch.rag.provider = scaleway
 *  - meilisearch.rag.apiKey   = <Scaleway IAM Secret Key, format e.g. b95394b8-…>
 *  - meilisearch.rag.model    = mistral-medium-3.5-128b (or any model from /v1/models)
 *
 * An explicit `meilisearch.rag.url` override still wins — handy for
 * staging or when Scaleway rotates the base URL.
 *
 * Rate-limit observation that drove this provider's introduction: at
 * burst-200-parallel embedding requests Scaleway returned 200 OK on all
 * 200 calls in 9 s. The previous provider (Infomaniak) capped at
 * 60 req/min and rejected the same burst with 95 % HTTP 429.
 */
final class ScalewayProvider extends OpenAiProvider
{
    protected const DEFAULT_BASE_URL = 'https://api.scaleway.ai';
    // Scaleway uses the standard OpenAI path: /v1/chat/completions.
    // Inherited from OpenAiProvider — no override needed.

    public function name(): string
    {
        return 'scaleway';
    }
}

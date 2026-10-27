<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

/**
 * The provider options (model, credentials, sampling) a site configures for
 * its assistant, as LlmProviderInterface::complete() takes them.
 *
 * One place for the mapping from site settings, so a caller outside the RAG
 * pipeline — a summary, a classification — talks to the same model with the
 * same credentials instead of keeping its own copy of these keys.
 */
final class LlmOptions
{
    /**
     * @param object $settings site settings (->get())
     * @param array<string,mixed> $overrides win over the site settings
     * @return array<string,mixed>
     */
    public static function fromSettings(object $settings, array $overrides = []): array
    {
        $options = [
            'model' => (string)$settings->get('meilisearch.rag.model', ''),
            'apiKey' => (string)$settings->get('meilisearch.rag.apiKey', ''),
            'url' => (string)$settings->get('meilisearch.rag.url', ''),
            'temperature' => (float)$settings->get('meilisearch.rag.temperature', 0.2),
            'timeout' => (int)$settings->get('meilisearch.rag.timeout', 60),
            // Tenant id for vendor-specific providers (currently Infomaniak).
            // Generic providers ignore it.
            'productId' => (string)$settings->get('meilisearch.infomaniak.productId', ''),
            'scalewayProjectId' => (string)$settings->get('meilisearch.scaleway.projectId', ''),
        ];
        $maxTokens = (int)$settings->get('meilisearch.rag.maxTokens', 0);
        if ($maxTokens > 0) {
            $options['maxTokens'] = $maxTokens;
        }
        foreach ($overrides as $key => $value) {
            $options[$key] = $value;
        }

        return $options;
    }
}

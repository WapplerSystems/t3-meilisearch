<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

/**
 * Generic OpenAI-compatible REST provider. Identical wire format to OpenAI
 * but takes an arbitrary base URL (vLLM, LM Studio, Together, Groq, Mistral,
 * Azure OpenAI behind a gateway, …). Use when you want to point at a custom
 * endpoint without committing to the `openAi` source label.
 */
final class RestProvider extends OpenAiProvider
{
    public function name(): string
    {
        return 'rest';
    }

    public function complete(array $messages, array $options): string
    {
        if (trim((string)($options['url'] ?? '')) === '') {
            throw new LlmException('REST: url is required for the generic OpenAI-compatible provider');
        }
        return parent::complete($messages, $options);
    }
}

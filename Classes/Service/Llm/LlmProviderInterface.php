<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Llm;

/**
 * Sync chat-completion abstraction. Implementations wrap one concrete LLM
 * provider (OpenAI, Anthropic, Ollama, generic OpenAI-compatible REST).
 *
 * Streaming is intentionally out of scope for Phase 4 — frontend rendering
 * for streamed tokens needs a separate SSE endpoint + JS client that's better
 * decided once we see real RAG usage patterns.
 */
interface LlmProviderInterface
{
    /**
     * Identifier matching the `meilisearch.rag.provider` setting value
     * (e.g. "openAi"). The factory uses this to map setting → implementation.
     */
    public function name(): string;

    /**
     * Run a chat completion. Messages follow OpenAI's role/content shape:
     *   [['role' => 'system', 'content' => '...'], ['role' => 'user', ...], ...]
     *
     * @param list<array{role:string,content:string}> $messages
     * @param array{
     *     model: string,
     *     apiKey?: string,
     *     url?: string,
     *     temperature?: float,
     *     maxTokens?: int,
     * } $options
     *
     * @return string The assistant's reply text.
     *
     * @throws LlmException When the provider rejects the request or returns
     *                     a malformed response.
     */
    public function complete(array $messages, array $options): string;
}

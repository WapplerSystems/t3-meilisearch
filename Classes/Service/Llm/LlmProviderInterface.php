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

    /**
     * Streaming variant — yields incremental text deltas as they arrive
     * from the provider. The concatenation of all yielded chunks equals
     * what complete() would have returned for the same input.
     *
     * Providers that don't actually support streaming should still
     * implement this by calling complete() and yielding the full result
     * once; the SSE middleware then delivers it as a single chunk,
     * preserving the "stream-capable" surface while paying the regular
     * sync latency.
     *
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     * @return iterable<string>
     *
     * @throws LlmException
     */
    public function streamComplete(array $messages, array $options): iterable;
}

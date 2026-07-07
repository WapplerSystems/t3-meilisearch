<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * One frame in the streaming RAG response. The streaming middleware
 * walks the iterator from askStreaming() and translates each chunk into
 * one SSE event:
 *
 *   sources → fires first, carries the search hits the LLM is about to
 *             see. Frontend renders them as a "Searching… found N
 *             documents" preview while the LLM warms up.
 *   token   → fires N times, one per text fragment from the provider.
 *             Frontend appends to the answer area.
 *   done    → fires last, carries the final citedIds (parsed from the
 *             complete response text) and status='ok'. Frontend swaps
 *             the partial-answer renderer for the final state.
 *   failed  → terminal alternative to done; carries an error message.
 *   noContext / disabled → terminal alternatives when retrieval found
 *             nothing or RAG isn't configured. No tokens are emitted.
 *   clarify → terminal alternative when the triage step decided the
 *             question is too ambiguous / underspecified to answer. Carries
 *             one clarifying question; no sources or tokens are emitted.
 */
final class RagStreamChunk
{
    public const TYPE_SOURCES = 'sources';
    public const TYPE_TOKEN = 'token';
    public const TYPE_DONE = 'done';
    public const TYPE_FAILED = 'failed';
    public const TYPE_NO_CONTEXT = 'no_context';
    public const TYPE_DISABLED = 'disabled';
    public const TYPE_SUGGESTIONS = 'suggestions';
    public const TYPE_END = 'end';
    public const TYPE_CLARIFY = 'clarify';

    /**
     * @param array<string,mixed> $data
     */
    private function __construct(
        public readonly string $type,
        public readonly array $data,
    ) {}

    /**
     * @param list<array<string,mixed>> $sources
     */
    public static function sources(array $sources): self
    {
        return new self(self::TYPE_SOURCES, ['sources' => $sources]);
    }

    public static function token(string $text): self
    {
        return new self(self::TYPE_TOKEN, ['text' => $text]);
    }

    /**
     * @param list<string> $citedIds
     */
    public static function done(string $answer, array $citedIds): self
    {
        return new self(self::TYPE_DONE, ['answer' => $answer, 'citedIds' => $citedIds]);
    }

    /**
     * @param list<array{type:string,label:string,value:string}> $suggestions
     */
    public static function suggestions(array $suggestions): self
    {
        return new self(self::TYPE_SUGGESTIONS, ['suggestions' => $suggestions]);
    }

    /**
     * Terminal sentinel — always the last frame on a successful answer. The
     * client closes the EventSource on this (not on `done`), so the optional
     * `suggestions` frame, which is generated after `done`, still arrives.
     */
    public static function end(): self
    {
        return new self(self::TYPE_END, []);
    }

    public static function failed(string $error): self
    {
        return new self(self::TYPE_FAILED, ['error' => $error]);
    }

    public static function noContext(): self
    {
        return new self(self::TYPE_NO_CONTEXT, []);
    }

    public static function disabled(): self
    {
        return new self(self::TYPE_DISABLED, []);
    }

    /**
     * Terminal frame: the triage step asked one clarifying question back
     * instead of answering. No `sources`/`token`/`end` follow — the client
     * renders the question and closes the stream.
     */
    public static function clarify(string $question): self
    {
        return new self(self::TYPE_CLARIFY, ['question' => $question]);
    }
}

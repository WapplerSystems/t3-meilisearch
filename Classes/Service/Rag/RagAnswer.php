<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * Result of a single RAG call. The template renders `$answer` as the chat
 * bubble; `$sources` provides the (potentially clickable) citation list so
 * users can verify what the LLM grounded its response on.
 */
final class RagAnswer
{
    /**
     * @param list<array<string,mixed>> $sources   Search hits sent to the LLM as context.
     * @param list<string>              $citedIds  IDs the LLM actually referenced in its response.
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
        public readonly array $citedIds,
        public readonly string $status,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self('', [], [], 'failed', $error);
    }

    public static function disabled(): self
    {
        return new self('', [], [], 'disabled', null);
    }

    public static function noContext(): self
    {
        return new self('', [], [], 'no_context', null);
    }
}

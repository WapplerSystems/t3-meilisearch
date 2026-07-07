<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * Verdict of the pre-answer triage step ({@see QueryClassifier}).
 *
 * Either the question is answerable from the retrieved context (the normal
 * path: retrieve → generate), or it is too ambiguous / underspecified to
 * answer well and the assistant should ask ONE clarifying question back
 * instead of guessing. This is the "recognise not-knowing and ask" lever of
 * agentic RAG — kept as a tiny immutable value object so both the streaming
 * and non-streaming RAG paths branch on the same shape.
 *
 * Plain DTO — never injected; excluded from the service container in
 * Services.yaml like {@see RagAnswer}.
 */
final class Clarification
{
    private function __construct(
        public readonly bool $needed,
        public readonly string $question,
    ) {}

    public static function answerable(): self
    {
        return new self(false, '');
    }

    public static function needed(string $question): self
    {
        return new self(true, trim($question));
    }
}

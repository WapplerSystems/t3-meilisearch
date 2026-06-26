<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * One (question, answer) round of a RAG conversation. Sources are not
 * stored — the next turn re-runs retrieval, and re-rendering past
 * sources from cached state would lie about whether they were actually
 * the basis of that answer.
 */
final class Turn
{
    /**
     * @param list<string> $citedIds source IDs the LLM cited for this answer
     */
    public function __construct(
        public readonly string $question,
        public readonly string $answer,
        public readonly array $citedIds = [],
    ) {}

    /**
     * Answer for display: inline "[id=…]" citation markers removed (incl. a
     * preceding space). The sources are surfaced separately, so the markers
     * are noise in the rendered transcript. The raw {@see $answer} keeps them
     * for any citation-aware processing.
     */
    public function getDisplayAnswer(): string
    {
        return (string)preg_replace('/\s*\[\s*id\s*=\s*[^\]]*\]/i', '', $this->answer);
    }
}

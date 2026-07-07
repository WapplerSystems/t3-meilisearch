<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * One (question, answer) round of a RAG conversation. Sources are not
 * stored — the next turn re-runs retrieval, and re-rendering past
 * sources from cached state would lie about whether they were actually
 * the basis of that answer.
 *
 * $kind distinguishes a normal answered turn ('answer') from a turn where
 * the assistant asked a clarifying question back instead of answering
 * ('clarification'). Both are kept in history so the follow-up reply gets
 * resolved by the query rewriter, but the clarification kind lets the
 * triage step avoid asking twice in a row (see QueryClassifier).
 */
final class Turn
{
    public const KIND_ANSWER = 'answer';
    public const KIND_CLARIFICATION = 'clarification';

    /**
     * @param list<string> $citedIds source IDs the LLM cited for this answer
     * @param self::KIND_* $kind
     */
    public function __construct(
        public readonly string $question,
        public readonly string $answer,
        public readonly array $citedIds = [],
        public readonly string $kind = self::KIND_ANSWER,
    ) {}

    public function isClarification(): bool
    {
        return $this->kind === self::KIND_CLARIFICATION;
    }

    /**
     * Answer rendered for the transcript: inline "[id=…]" citation markers
     * removed (sources are shown separately), HTML-escaped, then **bold**
     * rendered as <strong>. Mirrors RagAnswer::getAnswerHtml() so a server-
     * rendered history turn looks identical to a freshly streamed one. Output
     * is safe HTML — render with <f:format.raw>.
     */
    public function getDisplayAnswerHtml(): string
    {
        $text = (string)preg_replace('/\s*\[\s*id\s*=\s*[^\]]*\]/i', '', $this->answer);
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string)preg_replace('/\*\*([^*\n]+?)\*\*/u', '<strong>$1</strong>', $html);
    }
}

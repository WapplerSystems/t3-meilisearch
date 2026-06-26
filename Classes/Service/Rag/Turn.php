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

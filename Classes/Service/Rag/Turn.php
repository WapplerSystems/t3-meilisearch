<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * One (question, answer) round of a RAG conversation.
 *
 * The retrieval result is not stored: the next turn re-runs retrieval, and
 * re-rendering a past source list from cached state would claim those
 * documents were the basis of that answer. The documents the answer *cited*
 * are a different matter — they demonstrably were — so those ride along in
 * $citations, just enough of them to render the references again after a
 * reload. Without that the reload showed an answer whose references had
 * silently disappeared.
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
     * @param list<array<string,string>> $citations the cited documents, as
     *        {@see CitationRenderer::citationsFor()} trims them down
     * @param list<array<string,mixed>> $suggestions the buttons offered under
     *        this answer — {type, label, value} rows. Kept so a reloaded
     *        transcript still offers them: they were generated for this
     *        answer, and a reader who scrolls back to it wants the same
     *        choices, not an answer that lost its options.
     */
    public function __construct(
        public readonly string $question,
        public readonly string $answer,
        public readonly array $citedIds = [],
        public readonly string $kind = self::KIND_ANSWER,
        public readonly array $citations = [],
        public readonly array $suggestions = [],
    ) {}

    public function isClarification(): bool
    {
        return $this->kind === self::KIND_CLARIFICATION;
    }

    /**
     * Answer rendered for the transcript. Output is safe HTML — render with
     * <f:format.raw>.
     *
     * With the cited documents at hand the references come out exactly as in
     * a freshly streamed answer, numbered and with their legend. A turn stored
     * before citations were kept has none: then the markers are removed
     * instead, since a number without a title explains nothing.
     */
    public function getDisplayAnswerHtml(): string
    {
        return $this->citations === []
            ? CitationRenderer::withoutCitations($this->answer, $this->citedIds)
            : CitationRenderer::render($this->answer, $this->citations);
    }
}

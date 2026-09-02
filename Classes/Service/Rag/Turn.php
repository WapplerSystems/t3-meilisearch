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
     * Answer rendered for the transcript: citation markers removed, HTML
     * escaped, then **bold** rendered as <strong>. Output is safe HTML —
     * render with <f:format.raw>.
     *
     * A freshly answered turn turns its citations into numbered references
     * with a legend (RagAnswer::getAnswerHtml()). A stored turn cannot: it
     * keeps no sources, on purpose — re-rendering past sources from cached
     * state would claim they were the basis of that answer. Without titles
     * and URLs a number explains nothing, so the markers come out instead.
     *
     * Both shapes have to go. The model writes the "[id=pages-7]" the prompt
     * asks for, but also the bare "[pages-4331, pages-38322]", and only the
     * first form used to be matched here — so reloading a page with history
     * showed raw document ids in the middle of the text.
     *
     * Prose in brackets survives: a block is only dropped when every token in
     * it is either the "id=" chrome, an id this answer cited, or something
     * shaped like a document id. "[NOTE]" therefore stays.
     */
    public function getDisplayAnswerHtml(): string
    {
        $cited = [];
        foreach ($this->citedIds as $id) {
            $cited[mb_strtolower((string)$id)] = true;
        }
        // Eat an optional leading space so removing a citation after a word
        // does not leave a double space behind.
        $text = (string)preg_replace_callback(
            '/(\s*)\[([^\[\]]+)\]/',
            static function (array $block) use ($cited): string {
                if (!preg_match_all('/[A-Za-z0-9_:.\-]+/', $block[2], $tokens) || !isset($tokens[0])) {
                    return $block[0];
                }
                foreach ($tokens[0] as $token) {
                    $lower = mb_strtolower($token);
                    if ($lower === 'id' || isset($cited[$lower])) {
                        continue;
                    }
                    if (preg_match('/^[a-z][a-z0-9_]*-\d+(?:-l\d+)?$/', $lower) === 1) {
                        continue;
                    }

                    return $block[0];
                }

                return '';
            },
            $this->answer,
        );
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string)preg_replace('/\*\*([^*\n]+?)\*\*/u', '<strong>$1</strong>', $html);
    }
}

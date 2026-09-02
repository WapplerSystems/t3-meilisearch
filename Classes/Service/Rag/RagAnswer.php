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
        /** @var list<array{type:string,label:string,value:string}> */
        public readonly array $suggestions = [],
    ) {}

    /**
     * Immutable copy with action suggestions attached (followup / refine /
     * recommend). Generated after the answer so the model can base them on
     * the produced text + sources; never affects the answer itself.
     *
     * @param list<array{type:string,label:string,value:string}> $suggestions
     */
    public function withSuggestions(array $suggestions): self
    {
        return new self(
            $this->answer,
            $this->sources,
            $this->citedIds,
            $this->status,
            $this->error,
            $suggestions,
        );
    }

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

    /**
     * The question was too ambiguous / underspecified to answer well, so the
     * assistant asks one clarifying question back instead of guessing. The
     * clarifying question is carried in {@see $answer} so the template renders
     * it in the answer bubble; there are no sources or citations.
     */
    /**
     * The choices ride along as suggestions of type `clarify`: the suggestion
     * partial is rendered outside the status switch and already turns
     * {type,label,value} rows into re-ask links, so the visitor answers the
     * clarifying question with one click instead of retyping a product name.
     *
     * @param list<array{label:string,value:string}> $options
     */
    public static function clarification(string $question, array $options = []): self
    {
        $suggestions = [];
        foreach ($options as $option) {
            $suggestions[] = [
                'type' => 'clarify',
                'label' => (string)($option['label'] ?? ''),
                'value' => (string)($option['value'] ?? ''),
            ];
        }

        return new self(trim($question), [], [], 'clarify', null, $suggestions);
    }

    /**
     * Sources the FE template may show as a clickable citation list.
     * Strips hits of type 'knowledge_resource' — those are internal
     * RAG-grounding corpus (DITA topics imported via the
     * KnowledgeResource importer) which are deliberately hidden from
     * the public sources panel even though the LLM is told to ground
     * its answer in them. Used by `Rag/Ask.html`; the LLM-context path
     * keeps reading `$sources` directly so the full hit set still
     * grounds the answer.
     *
     * @return list<array<string,mixed>>
     */
    public function getPublicSources(): array
    {
        return array_values(array_filter(
            $this->sources,
            static fn(array $hit): bool => (string)($hit['type'] ?? '') !== 'knowledge_resource',
        ));
    }

    /**
     * One inline reference: the number, linking to the document, with the full
     * citation text as its tooltip so hovering says where it leads. Documents
     * without a uri (the grounding corpus keeps some without a public page)
     * become an <abbr>, which still carries the tooltip.
     *
     * @param array{number:int,text:string,uri:string} $ref
     */
    private static function citationAnchor(array $ref, string $label): string
    {
        $labelAttr = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tooltip = htmlspecialchars($ref['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($ref['uri'] === '') {
            return sprintf('<abbr title="%s">%s</abbr>', $tooltip, $labelAttr);
        }

        return sprintf(
            '<a href="%s" title="%s" rel="noopener" class="ws-meilisearch-rag-citation">%s</a>',
            htmlspecialchars($ref['uri'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $tooltip,
            $labelAttr,
        );
    }

    /**
     * HTML-rendered version of {@see $answer}: citation brackets become
     * numbered references, and the numbers are explained in a short legend
     * appended after the text.
     *
     * Input shapes the parser recognises:
     *   [id=help-1935]                  →  [1]
     *   [help-1935]                     →  [1]
     *   [id=help-1935, id=help-1936]    →  [1][2]
     *
     * Numbers are assigned in order of first appearance and reused, so a
     * document cited five times is still reference 1. Titles used to be
     * printed inline instead, which on a knowledge base that carries one
     * topic per product and release meant 40 to 60 characters per citation —
     * measured on one answer, a fifth of everything the reader saw — and five
     * pages sharing a title rendered as five identical links in a row.
     *
     * Sources that would read identically (same label, same qualifier — the
     * same topic per discipline, say) collapse onto one number pointing at the
     * first of them. Two references a reader cannot tell apart are noise, and
     * splitting them only moves that noise into the legend.
     *
     * Citations referencing `knowledge_resource` sources are stripped
     * entirely (including the surrounding bracket and a preceding space).
     * Those sources are the internal RAG grounding corpus, so leaving markers
     * in the prose would expose them through the back door. Mixed brackets
     * keep the other doc types and drop the knowledge-resource portion.
     *
     * Unknown tokens (something the parser sees but isn't in the returned
     * sources) keep the whole original block as plain text — better safe than
     * corrupting model output we don't understand.
     */
    public function getAnswerHtml(): string
    {
        $escaped = htmlspecialchars($this->answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($escaped === '' || $this->sources === []) {
            return $escaped;
        }
        $byId = [];
        foreach ($this->sources as $src) {
            $id = (string)($src['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $src;
            }
        }
        if ($byId === []) {
            return $escaped;
        }

        /** @var array<string,array{number:int,text:string,uri:string}> $refs keyed by display text */
        $refs = [];
        // Eat an optional leading space so deleting a citation that follows a
        // word doesn't leave a double space behind.
        $rewritten = (string)preg_replace_callback(
            '/(\s*)\[([^\[\]]+)\]/',
            static function (array $block) use ($byId, &$refs): string {
                if (!preg_match_all('/[A-Za-z0-9_:.\-]+/', $block[2], $tokens) || !isset($tokens[0])) {
                    return $block[0];
                }
                $numbers = [];
                $knowledgeResourceMatches = 0;
                foreach ($tokens[0] as $token) {
                    if (!isset($byId[$token])) {
                        continue;
                    }
                    $src = $byId[$token];
                    if ((string)($src['type'] ?? '') === 'knowledge_resource') {
                        $knowledgeResourceMatches++;
                        continue;
                    }
                    $text = self::citationText($src, $token);
                    if (!isset($refs[$text])) {
                        $refs[$text] = [
                            'number' => count($refs) + 1,
                            'text' => $text,
                            'uri' => (string)($src['uri'] ?? ''),
                        ];
                    }
                    $numbers[$refs[$text]['number']] = $refs[$text];
                }
                // Block contained only knowledge_resource tokens — strip it
                // along with any preceding whitespace.
                if ($numbers === [] && $knowledgeResourceMatches > 0) {
                    return '';
                }
                if ($numbers === []) {
                    return $block[0]; // nothing recognised — prose like [NOTE]
                }
                ksort($numbers);
                $out = $block[1];
                foreach ($numbers as $ref) {
                    $out .= '[' . self::citationAnchor($ref, (string)$ref['number']) . ']';
                }

                return $out;
            },
            $escaped,
        );

        // Markdown-light: render **bold** as <strong>. LLMs reach for emphasis
        // frequently — leaving the literal asterisks in the FE looks like
        // uninterpreted markup. Non-greedy, no newlines inside, no asterisks
        // inside (so `foo * bar` isn't matched). Runs after the citation
        // rewriter because htmlspecialchars left the `**` untouched.
        $rewritten = (string)preg_replace(
            '/\*\*([^*\n]+?)\*\*/u',
            '<strong>$1</strong>',
            $rewritten,
        );

        return $rewritten . self::citationLegend($refs);
    }

    /**
     * What a citation is called: the label a RagCitationLabelsEvent listener
     * set, plus its qualifier — falling back to the document title and, if
     * even that is missing, the id.
     *
     * @param array<string,mixed> $src
     */
    private static function citationText(array $src, string $id): string
    {
        $label = trim((string)($src['citationLabel'] ?? '')) ?: (trim((string)($src['title'] ?? '')) ?: $id);
        $qualifier = trim((string)($src['citationQualifier'] ?? ''));

        return $qualifier === '' ? $label : sprintf('%s (%s)', $label, $qualifier);
    }

    /**
     * Explains the numbers, listing only the documents the answer actually
     * cited. An <ol> so the browser numbers the rows — the references were
     * handed out in appearance order, so the two always line up.
     *
     * @param array<string,array{number:int,text:string,uri:string}> $refs
     */
    private static function citationLegend(array $refs): string
    {
        if ($refs === []) {
            return '';
        }
        usort($refs, static fn(array $a, array $b): int => $a['number'] <=> $b['number']);
        $rows = '';
        foreach ($refs as $ref) {
            $text = htmlspecialchars($ref['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rows .= $ref['uri'] === ''
                ? sprintf('<li>%s</li>', $text)
                : sprintf(
                    '<li><a href="%s" rel="noopener">%s</a></li>',
                    htmlspecialchars($ref['uri'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    $text,
                );
        }

        return '<ol class="ws-meilisearch-rag-citations">' . $rows . '</ol>';
    }
}

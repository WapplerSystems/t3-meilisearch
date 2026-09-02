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
    public static function clarification(string $question): self
    {
        return new self(trim($question), [], [], 'clarify', null);
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
     * HTML-rendered version of {@see $answer} with citation brackets
     * rewritten as `[<a href title>Article title</a>]` so the reader
     * sees what the source actually is, not a cryptic `help-1935` id.
     *
     * Input shapes the parser recognises:
     *   [id=help-1935]                  →  [<a>Title One</a>]
     *   [help-1935]                     →  [<a>Title One</a>]
     *   [id=help-1935, id=help-1936]    →  [<a>Title One</a>][<a>Title Two</a>]
     *
     * Multi-token blocks always split into separate bracket pairs —
     * visually consistent regardless of how the model groups its
     * citations. Original `id=` chrome and commas are dropped.
     *
     * Citations referencing `knowledge_resource` sources are stripped
     * entirely (including the surrounding bracket and a preceding
     * space). Those sources are the internal RAG grounding corpus —
     * already filtered out of the public sources panel — so leaving
     * inline `[Title]` markers in the prose would expose them through
     * the back door. Mixed brackets that reference both a knowledge-
     * resource and another doc type keep the other doc type's link
     * and drop the knowledge-resource portion.
     *
     * Unknown tokens (something the parser sees but isn't in the
     * returned sources) keep the whole original block as plain text
     * — better safe than corrupting model output we don't understand.
     *
     * Tooltip carries `<type> · <id>` so power-users can still
     * identify the exact doc behind the title.
     *
     * Mirrors the recognition shape of RagService::extractCitations()
     * so what the parser counts as "cited" is exactly what this
     * method linkifies.
     */
    /**
     * One citation link. Documents without a uri (the RAG corpus keeps some
     * that have no public page) become an <abbr> so the tooltip still names
     * what was cited.
     *
     * @param array{id:string,qualifier:string,uri:string,type:string} $member
     */
    private static function citationAnchor(array $member, string $text): string
    {
        $tooltip = $member['type'] !== ''
            ? sprintf('%s · %s', $member['type'], $member['id'])
            : $member['id'];
        $textAttr = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tooltipAttr = htmlspecialchars($tooltip, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($member['uri'] === '') {
            return sprintf('<abbr title="%s">%s</abbr>', $tooltipAttr, $textAttr);
        }

        return sprintf(
            '<a href="%s" title="%s" rel="noopener" class="ws-meilisearch-rag-citation">%s</a>',
            htmlspecialchars($member['uri'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $tooltipAttr,
            $textAttr,
        );
    }

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
        // Eat an optional single leading space so deleting a citation
        // that follows a word doesn't leave a double space behind.
        // `\s*` instead of ` ?` covers ` \n` etc. that the LLM
        // occasionally emits before a citation.
        $rewritten = (string)preg_replace_callback(
            '/(\s*)\[([^\[\]]+)\]/',
            static function (array $block) use ($byId): string {
                $leadingWs = $block[1];
                if (!preg_match_all('/[A-Za-z0-9_:.\-]+/', $block[2], $tokens) || !isset($tokens[0])) {
                    return $block[0];
                }
                $matched = [];
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
                    $matched[$token] = $src;
                }
                // Block contained only knowledge_resource tokens — strip
                // it entirely along with any preceding whitespace.
                if ($matched === [] && $knowledgeResourceMatches > 0) {
                    return '';
                }
                if ($matched === []) {
                    return $block[0]; // nothing recognised — leave alone (prose like [NOTE])
                }
                // Group by citation text so three releases of one topic
                // read "[Install packages (26 · Revit, 25 · AutoCAD)]" instead
                // of repeating the same title three times over. Every document
                // keeps its own link — the qualifier carries it.
                $groups = [];
                foreach ($matched as $id => $src) {
                    // citationLabel/citationQualifier are what
                    // RagCitationLabelsEvent listeners set; plain title when
                    // nobody had anything to add.
                    $label = trim((string)($src['citationLabel'] ?? '')) ?: (
                        trim((string)($src['title'] ?? '')) ?: $id
                    );
                    $groups[$label][] = [
                        'id' => $id,
                        'qualifier' => trim((string)($src['citationQualifier'] ?? '')),
                        'uri' => (string)($src['uri'] ?? ''),
                        'type' => trim((string)($src['type'] ?? '')),
                    ];
                }

                $out = $leadingWs;
                foreach ($groups as $label => $members) {
                    $qualified = array_values(array_filter(
                        $members,
                        static fn (array $member): bool => $member['qualifier'] !== '',
                    ));
                    // A single document, or several that brought no qualifier
                    // to tell them apart: link the label itself, once per
                    // document, exactly as before.
                    if (count($members) === 1 || $qualified === []) {
                        foreach ($members as $member) {
                            $text = $member['qualifier'] !== ''
                                ? sprintf('%s (%s)', $label, $member['qualifier'])
                                : $label;
                            $out .= '[' . self::citationAnchor($member, $text) . ']';
                        }
                        continue;
                    }
                    // Several documents sharing a label: the label is said
                    // once as plain text and each qualifier becomes the link.
                    $links = [];
                    foreach ($members as $member) {
                        $links[] = self::citationAnchor(
                            $member,
                            $member['qualifier'] !== '' ? $member['qualifier'] : $label,
                        );
                    }
                    $out .= sprintf(
                        '[%s (%s)]',
                        htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        implode(', ', $links),
                    );
                }
                return $out;
            },
            $escaped,
        );

        // Markdown-light: render **bold** as <strong>. LLMs reach for
        // emphasis frequently — leaving the literal asterisks in the
        // FE looks like uninterpreted markup. Non-greedy, no newlines
        // inside, no asterisks inside (so unrelated math/glob fragments
        // like `foo * bar` or solo `*` markers aren't matched). Runs
        // after the citation rewriter because htmlspecialchars left
        // the `**` characters untouched (they're not HTML-special).
        $rewritten = (string)preg_replace(
            '/\*\*([^*\n]+?)\*\*/u',
            '<strong>$1</strong>',
            $rewritten,
        );

        return $rewritten;
    }
}

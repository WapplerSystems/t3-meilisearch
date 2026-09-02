<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

/**
 * Renders a RAG answer for display: citation markers become numbered
 * references, the numbers get a legend, **bold** becomes <strong>.
 *
 * One implementation on purpose. The same rendering is needed for a freshly
 * generated answer ({@see RagAnswer}) and for a stored conversation turn
 * ({@see Turn}), and while the two had their own copies the identical defect
 * had to be fixed twice: both only recognised the "[id=pages-7]" shape the
 * prompt asks for and left the bare "[pages-4331, pages-38322]" the model also
 * writes standing in the visible text. The streamed client mirrors this in
 * RagStream.js — that copy cannot be avoided, but two PHP copies could.
 *
 * Plain static helpers — never injected, no state.
 */
final class CitationRenderer
{
    /**
     * Numbers are handed out in order of first appearance and reused, so a
     * document cited five times stays reference 1. Sources that would read
     * identically — the same topic per discipline, say — collapse onto one
     * number pointing at the first of them: two references a reader cannot
     * tell apart are noise wherever they are shown.
     *
     * Citations of `knowledge_resource` sources are dropped entirely: that
     * corpus is internal grounding and must surface neither as a link nor as
     * a raw id. A bracket whose tokens are all unknown is prose ("[NOTE]") and
     * survives untouched.
     *
     * @param list<array<string,mixed>> $sources documents available as citations
     */
    public static function render(string $answer, array $sources): string
    {
        $escaped = htmlspecialchars($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($escaped === '' || $sources === []) {
            return self::bold($escaped);
        }
        $byId = [];
        foreach ($sources as $src) {
            $id = (string)($src['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $src;
            }
        }
        if ($byId === []) {
            return self::bold($escaped);
        }

        /** @var array<string,array{number:int,text:string,uri:string}> $refs keyed by display text */
        $refs = [];
        // Eat an optional leading space so replacing a citation that follows a
        // word does not leave a double space behind.
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
                if ($numbers === [] && $knowledgeResourceMatches > 0) {
                    return '';
                }
                if ($numbers === []) {
                    return $block[0];
                }
                ksort($numbers);
                $out = $block[1];
                foreach ($numbers as $ref) {
                    $out .= '[' . self::anchor($ref, (string)$ref['number']) . ']';
                }

                return $out;
            },
            $escaped,
        );

        return self::bold($rewritten) . self::legend($refs);
    }

    /**
     * Display without any citations, for a turn that kept no sources: the
     * markers are removed rather than numbered, because without titles and
     * URLs a number explains nothing.
     *
     * Prose in brackets survives — a block is only dropped when every token in
     * it is the "id=" chrome, an id this answer cited, or something shaped like
     * a document id.
     *
     * @param list<string> $citedIds
     */
    public static function withoutCitations(string $answer, array $citedIds = []): string
    {
        $cited = [];
        foreach ($citedIds as $id) {
            $cited[mb_strtolower((string)$id)] = true;
        }
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
            $answer,
        );

        return self::bold(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The fields a stored turn needs to render its citations later: only the
     * documents the answer actually cited, and only what the reference itself
     * shows.
     *
     * @param list<array<string,mixed>> $sources
     * @param list<string> $citedIds
     * @return list<array<string,string>>
     */
    public static function citationsFor(array $sources, array $citedIds): array
    {
        $wanted = array_flip(array_map('strval', $citedIds));
        $out = [];
        foreach ($sources as $src) {
            $id = (string)($src['id'] ?? '');
            if ($id === '' || !isset($wanted[$id])) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'type' => (string)($src['type'] ?? ''),
                'uri' => (string)($src['uri'] ?? ''),
                'title' => (string)($src['title'] ?? ''),
                'citationLabel' => (string)($src['citationLabel'] ?? ''),
                'citationQualifier' => (string)($src['citationQualifier'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * What a citation is called: the label a RagCitationLabelsEvent listener
     * set plus its qualifier, falling back to the title and then the id.
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
     * One inline reference: the number, linking to the document, with the full
     * citation text as its tooltip. Documents without a uri become an <abbr>,
     * which still carries the tooltip.
     *
     * @param array{number:int,text:string,uri:string} $ref
     */
    private static function anchor(array $ref, string $label): string
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
     * Explains the numbers, listing only what the answer actually cited. An
     * <ol> so the browser numbers the rows — references were handed out in
     * appearance order, so the two line up.
     *
     * @param array<string,array{number:int,text:string,uri:string}> $refs
     */
    private static function legend(array $refs): string
    {
        if ($refs === []) {
            return '';
        }
        usort($refs, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
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

    /**
     * Markdown-light: **bold** as <strong>. LLMs reach for emphasis often, and
     * literal asterisks in the frontend look like uninterpreted markup. Runs
     * after the citation rewrite because htmlspecialchars leaves `**` alone.
     */
    private static function bold(string $html): string
    {
        return (string)preg_replace('/\*\*([^*\n]+?)\*\*/u', '<strong>$1</strong>', $html);
    }
}

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
    /** At most this many media cards under one answer, the best-ranked first. */
    private const MAX_MEDIA_CARDS = 3;

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
            return self::markdownLight($escaped);
        }
        $byId = [];
        $rank = [];
        foreach ($sources as $src) {
            $id = (string)($src['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $src;
                $rank[$id] ??= count($rank);
            }
        }
        if ($byId === []) {
            return self::markdownLight($escaped);
        }

        /** @var array<string,array{number:int,text:string,uri:string,note:string,media:array<string,string>|null,rank:int}> $refs keyed by display text */
        $refs = [];
        // Media sources (RagCitationLabelsEvent::setMedia) are numbered on
        // their own, so the plain source list below the answer reads 1, 2, 3
        // without gaps where the videos went.
        $counters = ['doc' => 0, 'media' => 0];
        // Eat an optional leading space so replacing a citation that follows a
        // word does not leave a double space behind.
        $rewritten = (string)preg_replace_callback(
            '/(\s*)\[([^\[\]]+)\]/',
            static function (array $block) use ($byId, $rank, &$refs, &$counters): string {
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
                        $media = self::media($src);
                        $kind = $media === null ? 'doc' : 'media';
                        $refs[$text] = [
                            'number' => ++$counters[$kind],
                            'text' => $text,
                            'uri' => $media['url'] ?? (string)($src['uri'] ?? ''),
                            'note' => trim((string)($src['citationNote'] ?? '')),
                            'media' => $media,
                            'rank' => $rank[$token] ?? PHP_INT_MAX,
                        ];
                    }
                    $numbers[($refs[$text]['media'] === null ? 'd' : 'm') . str_pad((string)$refs[$text]['number'], 4, '0', STR_PAD_LEFT)] = $refs[$text];
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
                    $out .= $ref['media'] === null
                        ? '[' . self::anchor($ref, (string)$ref['number']) . ']'
                        : self::mediaAnchor($ref);
                }

                return $out;
            },
            $escaped,
        );

        $media = array_filter($refs, static fn (array $ref): bool => $ref['media'] !== null);
        $docs = array_filter($refs, static fn (array $ref): bool => $ref['media'] === null);

        return self::markdownLight($rewritten) . self::mediaBlock($media) . self::legend($docs);
    }

    /**
     * The media payload a RagCitationLabelsEvent listener attached, or null
     * for an ordinary document.
     *
     * @param array<string,mixed> $src
     * @return array<string,string>|null
     */
    private static function media(array $src): ?array
    {
        $media = $src['citationMedia'] ?? null;
        if (!is_array($media) || trim((string)($media['title'] ?? '')) === '') {
            return null;
        }

        return array_map(static fn ($value): string => trim((string)$value), $media);
    }

    /**
     * Inline reference to a media source: the number with a play mark, so the
     * reader sees in the text where a video shows the step.
     *
     * @param array{number:int,text:string,uri:string} $ref
     */
    private static function mediaAnchor(array $ref): string
    {
        $label = '<span class="ws-meilisearch-rag-citation__play" aria-hidden="true"></span>' . $ref['number'];
        $tooltip = htmlspecialchars($ref['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($ref['uri'] === '') {
            return sprintf('<abbr class="ws-meilisearch-rag-citation ws-meilisearch-rag-citation--media" title="%s">%s</abbr>', $tooltip, $label);
        }

        return sprintf(
            '<a href="%s" title="%s" target="_blank" rel="noopener" class="ws-meilisearch-rag-citation ws-meilisearch-rag-citation--media">%s</a>',
            htmlspecialchars($ref['uri'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $tooltip,
            $label,
        );
    }

    /**
     * The block of media cards between the answer and the source list. The
     * best-ranked cited media source becomes the large card with the intro
     * sentence above it, up to two more follow as small cards. Every text
     * comes from the listener; the notes of media sources (free courses, the
     * interface may differ) close the block instead of the source list.
     *
     * Same markup as RagStream.js mediaBlock(); no whitespace between tags,
     * because the answer sits in a white-space: pre-wrap element.
     *
     * @param array<string,array{number:int,text:string,uri:string,note:string,media:array<string,string>|null,rank:int}> $refs
     */
    private static function mediaBlock(array $refs): string
    {
        if ($refs === []) {
            return '';
        }
        usort($refs, static fn (array $a, array $b): int => [$a['rank'], $a['number']] <=> [$b['rank'], $b['number']]);
        $refs = array_slice($refs, 0, self::MAX_MEDIA_CARDS);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $first = $refs[0]['media'] ?? [];

        $html = '<div class="ws-meilisearch-rag-media">';
        if (($first['heading'] ?? '') !== '') {
            $html .= '<p class="ws-meilisearch-rag-media__heading">' . $e($first['heading']) . '</p>';
        }
        if (($first['intro'] ?? '') !== '') {
            $html .= '<p class="ws-meilisearch-rag-media__intro">' . $e($first['intro']) . '</p>';
        }
        foreach ($refs as $index => $ref) {
            if ($index === 1 && (($first['more'] ?? '') !== '')) {
                $html .= '<p class="ws-meilisearch-rag-media__more">' . $e($first['more']) . '</p>';
            }
            if ($index === 1) {
                $html .= '<div class="ws-meilisearch-rag-media__list">';
            }
            $html .= self::mediaCard($ref, $index === 0);
        }
        if (count($refs) > 1) {
            $html .= '</div>';
        }
        foreach (array_unique(array_filter(array_map(static fn (array $ref): string => $ref['note'], $refs))) as $note) {
            $html .= '<p class="ws-meilisearch-rag-media__note">' . $e($note) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * @param array{number:int,uri:string,media:array<string,string>|null} $ref
     */
    private static function mediaCard(array $ref, bool $primary): string
    {
        $media = $ref['media'] ?? [];
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $class = 'ws-meilisearch-rag-media__card' . ($primary ? ' ws-meilisearch-rag-media__card--primary' : '');
        $inner = '<span class="ws-meilisearch-rag-media__thumb" aria-hidden="true">'
            . '<span class="ws-meilisearch-rag-media__play"></span>'
            . (($media['start'] ?? '') !== '' ? '<span class="ws-meilisearch-rag-media__start">' . $e($media['start']) . '</span>' : '')
            . '</span>'
            . '<span class="ws-meilisearch-rag-media__body">'
            . '<span class="ws-meilisearch-rag-media__title"><span class="ws-meilisearch-rag-media__number">' . $ref['number'] . '</span>' . $e($media['title'] ?? '') . '</span>'
            . (($media['context'] ?? '') !== '' ? '<span class="ws-meilisearch-rag-media__context">' . $e($media['context']) . '</span>' : '')
            . ($primary && ($media['meta'] ?? '') !== '' ? '<span class="ws-meilisearch-rag-media__meta">' . $e($media['meta']) . '</span>' : '')
            . ($primary && ($media['cta'] ?? '') !== '' ? '<span class="ws-meilisearch-rag-media__cta">' . $e($media['cta']) . '</span>' : '')
            . '</span>';
        if ($ref['uri'] === '') {
            return '<div class="' . $class . '">' . $inner . '</div>';
        }

        return '<a class="' . $class . '" href="' . $e($ref['uri']) . '" target="_blank" rel="noopener">' . $inner . '</a>';
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

        return self::markdownLight(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The fields a stored turn needs to render its citations later: only the
     * documents the answer actually cited, and only what the reference itself
     * shows.
     *
     * @param list<array<string,mixed>> $sources
     * @param list<string> $citedIds
     * @return list<array<string,mixed>>
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
                'citationNote' => (string)($src['citationNote'] ?? ''),
                'citationMedia' => is_array($src['citationMedia'] ?? null) ? $src['citationMedia'] : [],
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
     * Notes (RagCitationLabelsEvent::setLabel) follow the list, each distinct
     * note once: they qualify a kind of source, and three cited lessons of the
     * same course must not repeat the same sentence three times.
     *
     * @param array<string,array{number:int,text:string,uri:string,note?:string}> $refs
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

        $notes = '';
        foreach (array_unique(array_filter(array_map(static fn (array $ref): string => (string)($ref['note'] ?? ''), $refs))) as $note) {
            $notes .= sprintf(
                '<p class="ws-meilisearch-rag-citation-note">%s</p>',
                htmlspecialchars($note, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }

        return '<ol class="ws-meilisearch-rag-citations">' . $rows . '</ol>' . $notes;
    }

    /**
     * The notes a RagAnswerNotesEvent listener attached, below the source
     * list. Same markup as RagStream.js renderNotes().
     *
     * @param list<string> $notes
     */
    public static function notes(array $notes): string
    {
        $html = '';
        foreach ($notes as $note) {
            $note = trim((string)$note);
            if ($note !== '') {
                $html .= sprintf(
                    '<p class="ws-meilisearch-rag-answer-note">%s</p>',
                    htmlspecialchars($note, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                );
            }
        }

        return $html;
    }

    /**
     * Markdown-light. The model writes markdown whether asked to or not, and
     * anything not translated here reaches the reader as literal syntax —
     * "### Voraussetzungen" with the hashes, "*Hinweis:*" with the asterisks,
     * a row of dashes where a rule was meant.
     *
     * Deliberately inline-only: the answer is rendered inside a <p> with
     * white-space: pre-wrap, so a heading becomes bold text rather than an
     * <h3>, and the line structure the model produced is left alone. Runs
     * after the citation rewrite and after escaping, which leaves `#`, `*` and
     * backticks untouched — so nothing here can inject markup.
     */
    private static function markdownLight(string $html): string
    {
        $rules = [
            // "### Heading" and its trailing hashes, at the start of a line.
            '/^[ \t]{0,3}#{1,6}[ \t]+(.+?)[ \t]*#*[ \t]*$/mu' => '<strong>$1</strong>',
            // A horizontal rule has no place inside a paragraph; drop the line.
            '/^[ \t]{0,3}(?:-{3,}|\*{3,}|_{3,})[ \t]*\R?/mu' => '',
            // **bold** before *italic*, so the double asterisks are consumed
            // first and do not leave a stray <em> behind.
            '/\*\*([^*\n]+?)\*\*/u' => '<strong>$1</strong>',
            // *italic* — a single asterisk pair on one line. A bullet ("* item")
            // has no closing partner and stays as it is.
            '/(?<!\*)\*([^*\n]+?)\*(?!\*)/u' => '<em>$1</em>',
            // `code`
            '/`([^`\n]+?)`/u' => '<code>$1</code>',
        ];

        return (string)preg_replace(array_keys($rules), array_values($rules), $html);
    }
}

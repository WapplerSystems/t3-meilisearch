<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

/**
 * Turns indexable HTML into the plain text Meilisearch stores.
 *
 * The naive `strip_tags()` is not enough, because none of our sources put
 * whitespace around their markup: EXT:index concatenates a content block
 * as "<h1>Bauteile auslegen</h1>Erfahren Sie …", an RTE bodytext is
 * "<p>Absatz eins</p><p>Absatz zwei</p>". Stripping those tags glues the
 * words into "auslegenErfahren" / "einsAbsatz" — neither word can be
 * found any more, and the result snippet reads like a typo.
 *
 * So block-level tags become a space (what a browser renders as well) and
 * inline tags are dropped without one, so "auf <span>OK</span>." stays
 * "auf OK." rather than growing a space before the period.
 */
final class HtmlToText
{
    /**
     * Tags that do not introduce whitespace when a browser renders them.
     * Everything else counts as a block boundary. `br` and `wbr` sit on
     * opposite sides on purpose: `br` is a visible line break, `wbr` only
     * a hyphenation hint inside a word.
     */
    private const INLINE_TAGS = 'a|abbr|b|bdi|bdo|big|cite|code|data|del|dfn|em|font|i|ins|kbd|mark|nobr|q|rp|rt|ruby|s|samp|small|span|strong|sub|sup|time|tt|u|var|wbr';

    public function convert(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Script/style bodies are not content — drop them wholesale, otherwise
        // their source code ends up in the indexed text.
        $html = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1>#isu', ' ', $html);

        $padded = preg_replace(
            '#</?(?!(?:' . self::INLINE_TAGS . ')\b)[a-z][^>]*>#iu',
            ' ',
            $html,
        ) ?? $html;

        $text = strip_tags($padded);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}

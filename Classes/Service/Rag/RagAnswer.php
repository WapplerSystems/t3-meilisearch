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
    ) {}

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
        return (string)preg_replace_callback(
            '/\[([^\[\]]+)\]/',
            static function (array $block) use ($byId): string {
                // Tokenise the inner content using the same regex as
                // the citation parser. If at least one token is a
                // known source id, rebuild as a sequence of one
                // bracketed link per known token. Otherwise leave the
                // original block intact (could be prose like `[NOTE]`).
                if (!preg_match_all('/[A-Za-z0-9_:.\-]+/', $block[1], $tokens) || !isset($tokens[0])) {
                    return $block[0];
                }
                $matched = [];
                foreach ($tokens[0] as $token) {
                    if (isset($byId[$token])) {
                        $matched[$token] = $byId[$token];
                    }
                }
                if ($matched === []) {
                    return $block[0]; // nothing recognised — leave alone
                }
                $out = '';
                foreach ($matched as $id => $src) {
                    $title = trim((string)($src['title'] ?? '')) !== ''
                        ? trim((string)$src['title'])
                        : $id;
                    $type = trim((string)($src['type'] ?? ''));
                    $tooltip = $type !== ''
                        ? sprintf('%s · %s', $type, $id)
                        : $id;
                    $titleAttr = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $tooltipAttr = htmlspecialchars($tooltip, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $uri = (string)($src['uri'] ?? '');
                    if ($uri === '') {
                        $out .= sprintf('[<abbr title="%s">%s</abbr>]', $tooltipAttr, $titleAttr);
                        continue;
                    }
                    $out .= sprintf(
                        '[<a href="%s" title="%s" rel="noopener" class="ws-meilisearch-rag-citation">%s</a>]',
                        htmlspecialchars($uri, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        $tooltipAttr,
                        $titleAttr,
                    );
                }
                return $out;
            },
            $escaped,
        );
    }
}

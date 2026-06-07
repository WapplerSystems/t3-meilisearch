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
     * turned into <a href="…" title="source title"> links. Tokens
     * inside `[…]` blocks that don't correspond to a returned source
     * stay as plain text — same shape, no link / tooltip.
     *
     * Mirrors the parser in RagService::extractCitations(): outer
     * `[…]` blocks, inner tokens matching `[A-Za-z0-9_:.\-]+`.
     * Whatever the parser recognises as a citation, this method
     * linkifies; everything else (`[NOTE]`, prose square brackets)
     * passes through untouched.
     *
     * The answer text is HTML-escaped first so model-emitted angle
     * brackets / ampersands can't slip into the rendered card. Link
     * markup is injected after escaping.
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
                $linkified = preg_replace_callback(
                    '/[A-Za-z0-9_:.\-]+/',
                    static function (array $token) use ($byId): string {
                        $id = $token[0];
                        if (!isset($byId[$id])) {
                            return $id; // unknown token — passthrough
                        }
                        $src = $byId[$id];
                        $title = trim((string)($src['title'] ?? $id));
                        $titleAttr = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $uri = (string)($src['uri'] ?? '');
                        if ($uri === '') {
                            // No URL: render as <abbr> so the tooltip
                            // still works, but without a useless link.
                            return sprintf('<abbr title="%s">%s</abbr>', $titleAttr, $id);
                        }
                        return sprintf(
                            '<a href="%s" title="%s" rel="noopener" class="ws-meilisearch-rag-citation">%s</a>',
                            htmlspecialchars($uri, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                            $titleAttr,
                            $id,
                        );
                    },
                    $block[1],
                );
                return '[' . $linkified . ']';
            },
            $escaped,
        );
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Builds a chat completion prompt from a list of search hits + the user
 * question. The format is intentionally LLM-agnostic — same prompt works
 * for OpenAI, Anthropic, Ollama and any OpenAI-compatible REST endpoint.
 *
 * Hits are rendered as cited context blocks; the system prompt instructs
 * the model to ground answers in those blocks and emit `[id]` citation
 * markers that RagService parses back out.
 */
final class PromptBuilder
{
    private const FIELDS_PREFERRED = ['bodytext', 'description', 'abstract', 'teaser'];

    /**
     * Map of ISO-639-1 language codes to English language names. Used to
     * substitute `{{language}}` in the system prompt with a label every
     * LLM reliably recognises ("German", not "de_DE.utf8" or "Deutsch")
     * — the system message is in English by default, and answer-language
     * pins land best when written in the same language as the prompt.
     * Falls through to the locale name when a code isn't in the map.
     */
    private const ISO_639_1_TO_ENGLISH = [
        'de' => 'German',
        'en' => 'English',
        'fr' => 'French',
        'it' => 'Italian',
        'es' => 'Spanish',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'ru' => 'Russian',
        'tr' => 'Turkish',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'da' => 'Danish',
        'sv' => 'Swedish',
        'no' => 'Norwegian',
        'fi' => 'Finnish',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'el' => 'Greek',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
        'uk' => 'Ukrainian',
    ];

    /**
     * @param list<array<string,mixed>> $hits
     * @param int|null $forcedLanguageId  Caller-provided active language id;
     *     when null, falls back to the first hit's language (legacy path).
     *
     * @return list<array{role:string,content:string}>
     */
    public function build(
        Site $site,
        string $question,
        array $hits,
        string $systemPrompt,
        int $maxContextChars,
        ?int $forcedLanguageId = null,
    ): array {
        if ($forcedLanguageId !== null) {
            $languageId = $forcedLanguageId;
        } elseif ($hits !== [] && isset($hits[0]['language'])) {
            $languageId = (int)$hits[0]['language'];
        } else {
            $languageId = 0;
        }
        $languageLabel = $this->resolveLanguageLabel($site, $languageId);
        // Append an explicit language pin even when the prompt template
        // doesn't include {{language}}. Site editors who tweak the
        // systemPrompt setting commonly forget to keep the placeholder;
        // the answer then falls back to whatever language the LLM
        // defaults to (usually English with multilingual context). The
        // pin is added once, so a prompt that already references
        // {{language}} doesn't double up.
        if (!str_contains($systemPrompt, '{{language}}')) {
            $systemPrompt = rtrim($systemPrompt) . "\nAlways respond in {{language}}, regardless of the language used in the context excerpts.";
        }
        $resolvedSystem = strtr($systemPrompt, ['{{language}}' => $languageLabel]);

        $contextBlocks = [];
        foreach ($hits as $hit) {
            $contextBlocks[] = $this->renderHit($hit, $maxContextChars);
        }
        $contextSection = $contextBlocks === []
            ? '(no documents found)'
            : implode("\n\n---\n\n", $contextBlocks);

        $userContent = "Context excerpts:\n\n" . $contextSection
            . "\n\n---\n\nQuestion: " . trim($question);

        return [
            ['role' => 'system', 'content' => $resolvedSystem],
            ['role' => 'user', 'content' => $userContent],
        ];
    }

    /**
     * @param array<string,mixed> $hit
     */
    private function renderHit(array $hit, int $maxContextChars): string
    {
        $id = (string)($hit['id'] ?? '');
        $title = (string)($hit['title'] ?? '');
        $type = (string)($hit['type'] ?? '');

        $body = '';
        foreach (self::FIELDS_PREFERRED as $field) {
            if (!empty($hit[$field]) && is_string($hit[$field])) {
                $body = $hit[$field];
                break;
            }
        }
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        if ($maxContextChars > 0 && mb_strlen($body) > $maxContextChars) {
            $body = mb_substr($body, 0, $maxContextChars) . '…';
        }

        $header = sprintf('[id=%s | type=%s] %s', $id, $type, $title);
        return $body === '' ? $header : $header . "\n" . $body;
    }

    private function resolveLanguageLabel(Site $site, int $languageId): string
    {
        try {
            $language = $site->getLanguageById($languageId);
        } catch (\Throwable) {
            return 'English';
        }
        $iso = strtolower((string)$language->getLocale()->getLanguageCode());
        if ($iso !== '' && isset(self::ISO_639_1_TO_ENGLISH[$iso])) {
            return self::ISO_639_1_TO_ENGLISH[$iso];
        }
        // Fall back to the editor-chosen title (e.g. "Deutsch", "Englisch")
        // which is at least a real human name, even if not English.
        $title = trim((string)$language->getTitle());
        return $title !== '' ? $title : 'English';
    }
}

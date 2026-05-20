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
     * @param list<array<string,mixed>> $hits
     *
     * @return list<array{role:string,content:string}>
     */
    public function build(
        Site $site,
        string $question,
        array $hits,
        string $systemPrompt,
        int $maxContextChars,
    ): array {
        $languageId = 0;
        if ($hits !== [] && isset($hits[0]['language'])) {
            $languageId = (int)$hits[0]['language'];
        }
        $languageLabel = $this->resolveLanguageLabel($site, $languageId);
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
            return 'en';
        }
        return $language->getLocale()->getName();
    }
}

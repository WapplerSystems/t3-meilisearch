<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderInterface;

/**
 * Generates short, actionable next-step suggestions shown as buttons under
 * a RAG answer (decision support, "Stufe 1" — no tool calling):
 *  - followup:  a natural follow-up question (clicking re-asks it),
 *  - refine:    a narrower search query (clicking re-asks it),
 *  - recommend: a pointer to one of the retrieved sources.
 *
 * One short LLM call AFTER the answer, so it can base suggestions on the
 * produced text + sources. "recommend" is grounded: the model may only
 * reference a source id from the provided list, and the server resolves the
 * URL — no hallucinated links reach the user. Every suggestion is reduced
 * to the uniform shape {type, label, value}: value is the question/query to
 * re-ask, or — for recommend — the resolved source URL.
 *
 * Optional + safe: returns [] when disabled, on a non-ok answer, or on any
 * parse/LLM error, so it never blocks or corrupts the answer.
 */
final class SuggestionGenerator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TYPES = ['followup', 'refine', 'recommend'];

    private const SYSTEM_PROMPT = 'You generate short, actionable next-step suggestions for the user after a help-chat answer. Return ONLY a JSON array (no prose, no code fences). Each element is an object with: "type" = "followup" (a natural follow-up question), "refine" (a narrower search query) or "recommend" (point to one of the provided sources); "label" = the button text shown to the user (max ~8 words), in the same language as the user question; "value" = for followup/refine the question/query text to run, for recommend the EXACT source id from the Sources list. Only use "recommend" with an id that appears in the Sources list. Propose at most %d suggestions, most useful first. If nothing useful applies, return [].';

    /**
     * @param array<string,mixed> $baseLlmOptions provider connection options
     * @return list<array{type:string,label:string,value:string}>
     */
    public function generate(
        LlmProviderInterface $provider,
        object $settings,
        string $question,
        RagAnswer $answer,
        array $baseLlmOptions,
    ): array {
        if (!(bool)$settings->get('meilisearch.rag.suggestions.enabled', true)) {
            return [];
        }
        if ($answer->status !== 'ok' || trim($answer->answer) === '') {
            return [];
        }
        $max = max(1, min(8, (int)$settings->get('meilisearch.rag.suggestions.max', 4)));

        // Ground "recommend" in the real public sources: the model may only
        // reference these ids, the server resolves the URL.
        $byId = [];
        $sourceLines = [];
        foreach (array_slice($answer->getPublicSources(), 0, 8) as $src) {
            $id = (string)($src['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $url = (string)($src['publicUrl'] ?? '');
            if ($url === '') {
                $url = (string)($src['uri'] ?? '');
            }
            $title = trim((string)($src['title'] ?? ''));
            $byId[$id] = ['url' => $url, 'title' => $title];
            $sourceLines[] = $id . ' | ' . ($title !== '' ? $title : '(untitled)');
        }

        $messages = [
            ['role' => 'system', 'content' => sprintf(self::SYSTEM_PROMPT, $max)],
            ['role' => 'user', 'content' =>
                "User question:\n" . $question
                . "\n\nAnswer given:\n" . mb_substr(trim($answer->answer), 0, 1200)
                . "\n\nSources (id | title):\n" . ($sourceLines === [] ? '(none)' : implode("\n", $sourceLines))
                . "\n\nJSON suggestions:",
            ],
        ];

        $options = ['temperature' => 0.3, 'maxTokens' => 400] + $baseLlmOptions;
        try {
            $raw = $provider->complete($messages, $options);
        } catch (\Throwable $e) {
            $this->logger?->info('RAG suggestion generation failed: {message}', ['message' => $e->getMessage()]);
            return [];
        }

        return $this->parse($raw, $byId, $max);
    }

    /**
     * @param array<string,array{url:string,title:string}> $byId
     * @return list<array{type:string,label:string,value:string}>
     */
    private function parse(string $raw, array $byId, int $max): array
    {
        $json = $this->extractJsonArray($raw);
        if ($json === null) {
            return [];
        }
        try {
            $data = json_decode($json, true, 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? '')));
            if (!in_array($type, self::TYPES, true)) {
                continue;
            }
            $label = $this->clip((string)($row['label'] ?? ''), 120);
            $value = $this->clip((string)($row['value'] ?? ''), 240);
            if ($label === '' || $value === '') {
                continue;
            }
            if ($type === 'recommend') {
                // value is a source id → resolve to the real URL, drop if
                // unknown / linkless (never surface a hallucinated link).
                $src = $byId[$value] ?? null;
                if ($src === null || $src['url'] === '') {
                    continue;
                }
                $out[] = ['type' => 'recommend', 'label' => $label, 'value' => $src['url']];
            } else {
                $out[] = ['type' => $type, 'label' => $label, 'value' => $value];
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    private function extractJsonArray(string $raw): ?string
    {
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return substr($raw, $start, $end - $start + 1);
    }

    private function clip(string $s, int $max): string
    {
        $s = trim($s);
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) : $s;
    }
}

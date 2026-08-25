<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderInterface;

/**
 * Pre-answer triage: decide whether the user's question can be answered from
 * the retrieved context, or whether it is too ambiguous / underspecified and
 * the assistant should ask ONE clarifying question back instead of guessing.
 *
 * This is the "recognise not-knowing and ask" lever of agentic RAG. It runs
 * after retrieval — so it can see the actual candidate document titles and
 * offer concrete options — but before generation, so a clarification skips
 * the expensive answer call entirely.
 *
 * One cheap, deterministic LLM call (temp=0, small maxTokens). Degrades to
 * "answerable" whenever the feature is disabled, on the turn right after a
 * clarification (never ask twice in a row), or on any parse/transport error,
 * so a flaky triage never blocks an answer.
 */
final class QueryClassifier implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the triage step of a documentation assistant. Decide whether the user's latest question can be answered from the available documentation, or whether it is too ambiguous or underspecified to answer well without exactly one clarifying question.

You are given the conversation so far, the latest question, and the TITLES of the documents a search retrieved for it.

Rules:
- Default to ANSWERABLE. Clarifying is the rare exception, not the safe choice.
- Ask for clarification ONLY when BOTH hold: (a) the retrieved titles point to two or more mutually exclusive contexts (different products, editions or CAD platforms), AND (b) the correct answer would be materially different depending on which one the user means. If one answer covers all of them, it is ANSWERABLE.
- A broad or general question is ANSWERABLE. "What is X and what is it used for?" wants an overview, not a narrowing question.
- A question about a topic the documentation does not cover at all is ANSWERABLE. Do NOT ask a clarifying question about an off-topic subject — that only makes the assistant look confused. Let the answering step decline it politely.
- Missing detail that you could simply state conditionally ("in AutoCAD …, in Revit …") is not a reason to clarify.
- When clarification is genuinely needed, ask ONE short, specific question in the same language as the user's latest message. Prefer concrete options taken from the retrieved titles (e.g. product or edition names) over a generic "please be more specific".
- Never ask the user merely to rephrase. Ask for the specific missing fact.

Output ONLY minified JSON, nothing else:
{"answerable": true}
or
{"answerable": false, "question": "<your single clarifying question>"}
PROMPT;

    /**
     * @param object $settings Site settings (->get()).
     * @param list<array<string,mixed>> $hits retrieved candidate documents.
     * @param array<string,mixed> $baseLlmOptions provider connection options
     *     (model, apiKey, url, timeout, …). temperature + maxTokens are
     *     overridden here for a short, deterministic verdict.
     */
    public function classify(
        LlmProviderInterface $provider,
        object $settings,
        Conversation $conversation,
        string $question,
        array $hits,
        array $baseLlmOptions,
    ): Clarification {
        if (!(bool)$settings->get('meilisearch.rag.clarify.enabled', false)) {
            return Clarification::answerable();
        }
        if (!$this->productAmbiguityPresent($settings, $question, $hits)) {
            // Nothing to disambiguate — don't even spend an LLM call on it.
            return Clarification::answerable();
        }
        // Never ask for clarification twice in a row: the user's reply to a
        // clarifying question is by definition the answer to it.
        if ($conversation->lastTurnIsClarification()) {
            return Clarification::answerable();
        }

        $historyTurns = max(1, (int)$settings->get('meilisearch.rag.rewriteHistoryTurns', 3));
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' =>
                "Conversation so far:\n" . $this->renderHistory($conversation, $historyTurns)
                . "\n\nLatest question: " . $question
                . "\n\nRetrieved document titles:\n" . $this->renderTitles($hits)
                . "\n\nVerdict JSON:",
            ],
        ];

        // Short + deterministic, like the query rewriter: our temperature /
        // maxTokens win; provider connection bits come from the caller.
        $options = ['temperature' => 0.0, 'maxTokens' => 200] + $baseLlmOptions;

        try {
            $raw = $provider->complete($messages, $options);
        } catch (\Throwable $e) {
            $this->logger?->info('RAG query classification failed, treating as answerable: {message}', [
                'message' => $e->getMessage(),
            ]);
            return Clarification::answerable();
        }

        return $this->parse($raw);
    }

    private function renderHistory(Conversation $conversation, int $maxTurns): string
    {
        $turns = $conversation->turns;
        if ($turns === []) {
            return '(none)';
        }
        if (count($turns) > $maxTurns) {
            $turns = array_slice($turns, -$maxTurns);
        }
        $lines = [];
        foreach ($turns as $turn) {
            $lines[] = 'User: ' . $turn->question;
            $answer = trim($turn->answer);
            if (mb_strlen($answer) > 300) {
                $answer = mb_substr($answer, 0, 300) . '…';
            }
            $lines[] = 'Assistant: ' . $answer;
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<array<string,mixed>> $hits
     */
    private function renderTitles(array $hits): string
    {
        $lines = [];
        foreach ($hits as $hit) {
            $title = trim((string)($hit['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $type = trim((string)($hit['type'] ?? ''));
            $lines[] = $type !== '' ? sprintf('- %s (%s)', $title, $type) : '- ' . $title;
        }
        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    private function parse(string $raw): Clarification
    {
        $raw = trim($raw);
        // Strip ```json fences some models wrap around the JSON.
        if (str_starts_with($raw, '```')) {
            $raw = trim((string)preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $raw));
        }
        // Grab the first {...} block so trailing prose can't break decoding.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Clarification::answerable();
        }
        if (!is_array($data) || ($data['answerable'] ?? true) !== false) {
            return Clarification::answerable();
        }
        $question = trim((string)($data['question'] ?? ''));
        // A "not answerable" verdict without an actual question is useless —
        // answer rather than show an empty clarification bubble.
        if ($question === '' || mb_strlen($question) > 400) {
            return Clarification::answerable();
        }
        return Clarification::needed($question);
    }

    /**
     * Deterministic gate in front of the LLM triage: only questions that are
     * ambiguous *about the product* may be clarified at all.
     *
     * The triage prompt alone could not carry this. Told to weigh whether an
     * answer "would materially differ", the model still invented a conflict
     * out of the brand name itself — asking whether "LINEAR" meant the
     * software or a specific product for a licence question the pipeline
     * answers perfectly well (0.768 with the classifier switched off).
     *
     * So the decision is made from data instead of judgement:
     *
     *   - the user already named a product  → answerable, they were specific
     *   - the retrieved titles mention fewer than two products → answerable,
     *     there is nothing to choose between
     *   - otherwise → let the LLM decide, as before
     *
     * Terms come from `meilisearch.rag.clarify.productTerms`. An empty list
     * leaves the gate open, so existing installations keep their behaviour.
     *
     * @param list<array<string,mixed>> $hits
     */
    private function productAmbiguityPresent(object $settings, string $question, array $hits): bool
    {
        $terms = $settings->get('meilisearch.rag.clarify.productTerms', []);
        if (!is_array($terms) || $terms === []) {
            return true;
        }
        $terms = array_values(array_filter(array_map(
            static fn ($t): string => mb_strtolower(trim((string)$t)),
            $terms,
        ), static fn (string $t): bool => $t !== ''));
        if ($terms === []) {
            return true;
        }

        $askedFor = mb_strtolower($question);
        foreach ($terms as $term) {
            if (str_contains($askedFor, $term)) {
                return false;
            }
        }

        $haystack = '';
        foreach ($hits as $hit) {
            $haystack .= ' ' . mb_strtolower((string)($hit['title'] ?? ''));
        }
        $found = [];
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $found[$term] = true;
            }
        }
        return count($found) >= 2;
    }
}

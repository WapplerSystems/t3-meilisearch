<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderInterface;

/**
 * Conversational query rewriting for multi-turn RAG.
 *
 * The retrieval step searches the index with the user's latest message.
 * In a follow-up ("und der Preis?", "und auf Englisch?") that message is
 * elliptical — on its own it retrieves the wrong context, or none, because
 * the real subject lives in earlier turns. This service makes one cheap,
 * deterministic LLM call that folds the conversation history into a single
 * self-contained search query. It is used for retrieval ONLY; the answer
 * prompt still receives the user's original wording plus the full history.
 *
 * On the FIRST turn there is no history to fold in, but the question still
 * needs work: a natural-language question makes a poor keyword query. The
 * stop-word filter alone does not get there — measured against the LINEAR
 * help corpus, "Wie gebe ich eine nicht mehr benötigte LINEAR Lizenz wieder
 * frei?" strips to "gebe benötigte LINEAR Lizenz frei" and retrieves
 * NOTHING, after which the fallback ladder serves unrelated topics. The
 * same question as "Lizenz freigeben" puts the correct document at rank 1.
 * Reaching that form means turning "gebe … wieder frei" into the infinitive
 * "freigeben" — German lemmatisation, which no word list does reliably, so
 * the model does it.
 *
 * Degrades to the original question on any failure or when disabled via
 * setting — so a flaky rewrite never breaks retrieval.
 */
final class QueryRewriter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * First turn: there is nothing to resolve, only to condense. Asking for
     * "topic noun + action verb in the infinitive" is not stylistic — it is
     * the shape that was measured to work: three of four regression
     * questions land their expected document at rank 1 in that form and
     * miss entirely as a full sentence.
     */
    private const KEYWORD_PROMPT = 'You turn a question into a short search query for a document search engine. Keep the topic noun and the action, with the verb in the infinitive: "Wie gebe ich eine Lizenz wieder frei?" becomes "Lizenz freigeben", and "How do I install software packages?" becomes "install software packages". Drop question words, articles, pronouns and filler. Keep domain and product terms verbatim, but drop a product name when it is not part of what is being asked about. Two to four words is usually right. Output ONLY the query text — no quotes, no labels, no explanation.';

    private const SYSTEM_PROMPT = 'You rewrite the user\'s latest message into a single, self-contained search query for a document search engine. Use the conversation so far only to resolve pronouns, ellipsis and the implicit subject. Keep the user\'s domain/product terms verbatim. Output ONLY the query text — no quotes, no labels, no explanation. If the latest message is already self-contained, output it unchanged.';

    /**
     * Appended to both prompts when the caller names the retrieval language.
     *
     * "in the same language as the question" was already in the prompt and was
     * NOT enough: the single German one-shot example anchored the output
     * language harder than the trailing instruction, so English questions came
     * back as German keyword queries — "How do I install software packages in
     * LINEAR Revit?" became "Softwarepakete installieren Revit", which was then
     * searched with `language = 1` and matched nothing. Measured against the
     * live corpus, every English question failed that way, while German always
     * worked. Naming the target language explicitly removes the guesswork; the
     * examples now cover both languages so neither anchors the other away.
     */
    private const LANGUAGE_SUFFIX = ' The query MUST be written in %s, whatever language the question uses — it is matched against documents in %s only.';

    /**
     * @param object $settings Site settings (->get()).
     * @param array<string,mixed> $baseLlmOptions provider connection options
     *        (model, apiKey, url, timeout, …). temperature + maxTokens are
     *        overridden here for a short, deterministic rewrite.
     */
    public function rewrite(
        LlmProviderInterface $provider,
        object $settings,
        Conversation $conversation,
        string $question,
        array $baseLlmOptions,
        ?string $languageLabel = null,
    ): string {
        if (!(bool)$settings->get('meilisearch.rag.conversationalRewrite', true)) {
            return $question;
        }

        $pin = $languageLabel !== null && $languageLabel !== ''
            ? sprintf(self::LANGUAGE_SUFFIX, $languageLabel, $languageLabel)
            : '';

        if ($conversation->isEmpty()) {
            if (!(bool)$settings->get('meilisearch.rag.keywordRewrite', true)) {
                return $question;
            }
            $messages = [
                ['role' => 'system', 'content' => self::KEYWORD_PROMPT . $pin],
                ['role' => 'user', 'content' => $question . "\n\nSearch query:"],
            ];
            return $this->callProvider($provider, $messages, $baseLlmOptions, $question);
        }

        $historyTurns = max(1, (int)$settings->get('meilisearch.rag.rewriteHistoryTurns', 3));
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT . $pin],
            ['role' => 'user', 'content' =>
                "Conversation so far:\n" . $this->renderHistory($conversation, $historyTurns)
                . "\n\nLatest message: " . $question
                . "\n\nStandalone search query:",
            ],
        ];

        // Short + deterministic: our temperature/maxTokens win over the
        // RAG answer settings; the provider connection bits (model, apiKey,
        // url, timeout) come from the caller's options.
        return $this->callProvider($provider, $messages, $baseLlmOptions, $question);
    }

    /**
     * One short, deterministic completion, with the original question as the
     * safety net. Shared by both rewrite modes so a failure degrades the same
     * way in each: retrieval continues with what the user actually typed.
     *
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $baseLlmOptions
     */
    private function callProvider(
        LlmProviderInterface $provider,
        array $messages,
        array $baseLlmOptions,
        string $question,
    ): string {
        $options = ['temperature' => 0.0, 'maxTokens' => 64] + $baseLlmOptions;

        try {
            $rewritten = $provider->complete($messages, $options);
        } catch (\Throwable $e) {
            $this->logger?->info('RAG query rewrite failed, using original question: {message}', [
                'message' => $e->getMessage(),
            ]);
            return $question;
        }

        $rewritten = $this->sanitize($rewritten);
        // Guard against a model that ignored the instruction (empty output,
        // or a verbose paragraph instead of a query) — fall back to original.
        if ($rewritten === '' || mb_strlen($rewritten) > 240) {
            return $question;
        }
        return $rewritten;
    }

    private function renderHistory(Conversation $conversation, int $maxTurns): string
    {
        $turns = $conversation->turns;
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

    private function sanitize(string $text): string
    {
        $text = trim($text);
        // Collapse to the first non-empty line — models sometimes prepend a
        // "Here is the query:" line or append trailing notes.
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $text = $line;
                break;
            }
        }
        // Strip wrapping quotes/backticks added despite the instruction.
        return trim($text, " \t\"'`");
    }
}

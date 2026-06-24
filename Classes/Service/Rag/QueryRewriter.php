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
 * Degrades to the original question on any failure, when disabled via
 * setting, or on the first turn (no history) — so it can never block or
 * delay a first answer, and a flaky rewrite never breaks retrieval.
 */
final class QueryRewriter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SYSTEM_PROMPT = 'You rewrite the user\'s latest message into a single, self-contained search query for a document search engine. Use the conversation so far only to resolve pronouns, ellipsis and the implicit subject. Keep the user\'s domain/product terms verbatim. Output ONLY the query text — no quotes, no labels, no explanation — in the same language as the latest message. If the latest message is already self-contained, output it unchanged.';

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
    ): string {
        if ($conversation->isEmpty()) {
            return $question;
        }
        if (!(bool)$settings->get('meilisearch.rag.conversationalRewrite', true)) {
            return $question;
        }

        $historyTurns = max(1, (int)$settings->get('meilisearch.rag.rewriteHistoryTurns', 3));
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' =>
                "Conversation so far:\n" . $this->renderHistory($conversation, $historyTurns)
                . "\n\nLatest message: " . $question
                . "\n\nStandalone search query:",
            ],
        ];

        // Short + deterministic: our temperature/maxTokens win over the
        // RAG answer settings; the provider connection bits (model, apiKey,
        // url, timeout) come from the caller's options.
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

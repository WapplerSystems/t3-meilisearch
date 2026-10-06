<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderInterface;

/**
 * Re-orders retrieved documents by how well they answer the question, with
 * one short LLM call (meilisearch.rag.rerank.enabled).
 *
 * Hybrid retrieval ranks by term and vector similarity, which cannot tell a
 * lesson that answers "where do I enter the air-tightness measurement" from
 * five neighbouring lessons of the same course that merely talk about it —
 * with several courses and the knowledge base in one index, those neighbours
 * pushed the right document out of the context (16 of 24 test questions
 * found it in the top three, against 22 of 24 with the course alone).
 *
 * No dedicated rerank model is assumed: the site's own chat provider ranks a
 * numbered list of candidates (title plus the start of the text) and answers
 * with the numbers in order. Any failure — timeout, malformed reply — keeps
 * the retrieval order, so the reranker can only improve an answer, never
 * lose one. Candidates the model leaves out are appended in their original
 * order for the same reason.
 */
final class Reranker implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Characters of each candidate's text shown to the model. */
    private const EXCERPT_CHARS = 700;

    /**
     * @param list<array<string,mixed>> $hits retrieval order
     * @param array<string,mixed> $llmOptions
     * @return list<array<string,mixed>> reranked, same documents
     */
    public function rerank(LlmProviderInterface $provider, array $llmOptions, string $question, array $hits): array
    {
        if (count($hits) < 2 || trim($question) === '') {
            return $hits;
        }

        $list = '';
        foreach (array_values($hits) as $i => $hit) {
            $text = preg_replace('/\s+/u', ' ', (string)($hit['content'] ?? $hit['abstract'] ?? '')) ?? '';
            $list .= sprintf(
                "[%d] %s\n%s\n\n",
                $i + 1,
                trim((string)($hit['title'] ?? '')),
                mb_substr(trim($text), 0, self::EXCERPT_CHARS),
            );
        }

        $messages = [
            ['role' => 'system', 'content' =>
                'You rank documents for a support chat. Given a question and numbered documents, '
                . 'order the documents by how directly each one answers the question: a document that '
                . 'contains the steps or the explanation asked for comes first, a document that is merely '
                . 'about the same topic comes later. Reply with JSON only: {"order": [numbers]}, '
                . 'every number exactly once, best first.',
            ],
            ['role' => 'user', 'content' => "Question: " . trim($question) . "\n\nDocuments:\n" . $list . 'JSON:'],
        ];
        // Short and deterministic; the caller's model/apiKey/url/timeouts stay.
        $options = ['temperature' => 0.0, 'maxTokens' => 200] + $llmOptions;
        $options['temperature'] = 0.0;
        $options['maxTokens'] = 200;

        try {
            $raw = $provider->complete($messages, $options);
        } catch (\Throwable $e) {
            $this->logger?->info('RAG rerank failed, keeping retrieval order: {message}', ['message' => $e->getMessage()]);
            return $hits;
        }

        $order = $this->parseOrder($raw, count($hits));
        if ($order === []) {
            $this->logger?->info('RAG rerank reply unusable, keeping retrieval order: {raw}', ['raw' => mb_substr($raw, 0, 200)]);
            return $hits;
        }

        $hits = array_values($hits);
        $result = [];
        foreach ($order as $n) {
            $result[] = $hits[$n - 1];
        }
        foreach ($hits as $i => $hit) {
            if (!in_array($i + 1, $order, true)) {
                $result[] = $hit;
            }
        }

        return $result;
    }

    /**
     * @return list<int> 1-based candidate numbers, deduplicated, in range
     */
    private function parseOrder(string $raw, int $count): array
    {
        if (preg_match('/\{.*\}/s', $raw, $m) !== 1) {
            return [];
        }
        try {
            $data = json_decode($m[0], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $order = [];
        foreach ((array)($data['order'] ?? []) as $n) {
            $n = (int)$n;
            if ($n >= 1 && $n <= $count && !in_array($n, $order, true)) {
                $order[] = $n;
            }
        }

        return $order;
    }
}

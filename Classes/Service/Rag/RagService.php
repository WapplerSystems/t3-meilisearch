<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Event\AfterRagAnswerEvent;
use WapplerSystems\Meilisearch\Event\BeforeLlmCallEvent;
use WapplerSystems\Meilisearch\Event\BeforeRagQueryEvent;
use WapplerSystems\Meilisearch\Service\Llm\LlmException;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderRegistry;
use WapplerSystems\Meilisearch\Service\SearchService;

/**
 * Retrieval-Augmented Generation orchestrator.
 *
 * Flow:
 *   1. Run a search (hybrid if both useHybrid setting + embedder configured)
 *      to fetch the top-N hits for the question.
 *   2. Build a chat prompt that pins the LLM to those hits and asks it to
 *      cite them by id.
 *   3. Call the configured LLM provider.
 *   4. Parse `[id=...]` markers from the response and zip them back to the
 *      original hits so the template can render clickable citations.
 *
 * Failures degrade gracefully: an LlmException turns into RagAnswer::failed,
 * keeping the surrounding page rendering alive.
 */
final class RagService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Citation patterns. We grab every `[...]` block (excluding nested
     * brackets) and then pick id-shaped tokens out of the inner text — the
     * final filter against $validIds keeps random brackets (markdown links,
     * regex literals, …) from leaking through.
     *
     * Forms the LLM has been observed to emit despite the prompt asking for
     * plain `[id]`:
     *   [pages-42]                    (the intended form)
     *   [id=pages-42]                 (LLM treated "id" in the prompt as a key)
     *   [id=pages-42, id=news-7]      (multiple grouped in one bracket)
     *   [pages-42, news-7]            (bare, comma-separated)
     *   [pages-42][news-7]            (chained)
     * The block+token approach catches all of these.
     */
    private const CITATION_BLOCK_PATTERN = '/\[([^\[\]]+)\]/';
    private const CITATION_TOKEN_PATTERN = '/[A-Za-z0-9_:.\-]+/';

    public function __construct(
        private readonly SearchService $searchService,
        private readonly LlmProviderRegistry $providerRegistry,
        private readonly PromptBuilder $promptBuilder,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function ask(Site $site, string $question, array $options = []): RagAnswer
    {
        $question = trim($question);
        if ($question === '') {
            return RagAnswer::noContext();
        }

        $settings = $site->getSettings();
        $providerName = trim((string)$settings->get('meilisearch.rag.provider', ''));
        if ($providerName === '') {
            return RagAnswer::disabled();
        }
        $provider = $this->providerRegistry->get($providerName);
        if ($provider === null) {
            $this->logger?->error('RAG provider "{name}" not registered', ['name' => $providerName]);
            return RagAnswer::failed('provider "' . $providerName . '" not registered');
        }

        $maxHits = max(1, (int)$settings->get('meilisearch.rag.maxContextHits', 5));
        $maxChars = max(100, (int)$settings->get('meilisearch.rag.maxContextChars', 1500));
        $useHybrid = (bool)$settings->get('meilisearch.rag.useHybrid', true)
            && trim((string)$settings->get('meilisearch.embedder.source', '')) !== '';
        $conversation = $options['conversation'] ?? null;
        if (!$conversation instanceof Conversation) {
            $conversation = Conversation::empty();
        }

        $event = new BeforeRagQueryEvent($question, array_merge([
            'perPage' => $maxHits,
            'hybrid' => $useHybrid,
        ], $options));
        $this->eventDispatcher->dispatch($event);

        $searchResult = $this->searchService->search($site, $event->question, $event->options);
        $hits = array_values(array_slice($searchResult->hits, 0, $maxHits));
        if ($hits === []) {
            $answer = RagAnswer::noContext();
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, $answer));
            return $answer;
        }

        $systemPrompt = (string)$settings->get('meilisearch.rag.systemPrompt', '');
        $currentTurnMessages = $this->promptBuilder->build(
            $site,
            $event->question,
            $hits,
            $systemPrompt,
            $maxChars,
        );

        // Splice prior turns between the system prompt and the current
        // user message. PromptBuilder always emits [system, user]; we
        // insert the conversation history between those two so the LLM
        // sees: system → history → current user (with fresh context).
        $messages = $this->withConversation($currentTurnMessages, $conversation);

        $llmOptions = [
            'model' => (string)$settings->get('meilisearch.rag.model', ''),
            'apiKey' => (string)$settings->get('meilisearch.rag.apiKey', ''),
            'url' => (string)$settings->get('meilisearch.rag.url', ''),
            'temperature' => (float)$settings->get('meilisearch.rag.temperature', 0.2),
            // Tenant id for vendor-specific providers (currently Infomaniak).
            // Generic providers ignore it.
            'productId' => (string)$settings->get('meilisearch.infomaniak.productId', ''),
        ];

        $before = new BeforeLlmCallEvent($messages, $llmOptions);
        $this->eventDispatcher->dispatch($before);

        try {
            $responseText = $before->response ?? $provider->complete($before->messages, $before->options);
        } catch (LlmException $e) {
            $this->logger?->error('RAG LLM call failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $answer = RagAnswer::failed($e->getMessage());
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, $answer));
            return $answer;
        }

        $citedIds = $this->extractCitations($responseText, $hits);
        $answer = new RagAnswer(
            answer: trim($responseText),
            sources: $hits,
            citedIds: $citedIds,
            status: 'ok',
        );
        $after = new AfterRagAnswerEvent($event->question, $answer);
        $this->eventDispatcher->dispatch($after);
        return $after->answer;
    }

    /**
     * Streaming variant of ask(): yields RagStreamChunk events instead of
     * returning a single RagAnswer. The first chunk is `sources` (so the
     * UI can render "Searching… found N documents" before tokens start
     * arriving); then a stream of `token` chunks; then a terminal `done`
     * chunk with the full answer + parsed citation IDs. Failure modes
     * (`disabled`, `no_context`, `failed`) yield exactly one terminal
     * chunk and stop.
     *
     * @param array<string,mixed> $options
     * @return iterable<RagStreamChunk>
     */
    public function askStreaming(Site $site, string $question, array $options = []): iterable
    {
        $question = trim($question);
        if ($question === '') {
            yield RagStreamChunk::noContext();
            return;
        }

        $settings = $site->getSettings();
        $providerName = trim((string)$settings->get('meilisearch.rag.provider', ''));
        if ($providerName === '') {
            yield RagStreamChunk::disabled();
            return;
        }
        $provider = $this->providerRegistry->get($providerName);
        if ($provider === null) {
            yield RagStreamChunk::failed('provider "' . $providerName . '" not registered');
            return;
        }

        $maxHits = max(1, (int)$settings->get('meilisearch.rag.maxContextHits', 5));
        $maxChars = max(100, (int)$settings->get('meilisearch.rag.maxContextChars', 1500));
        $useHybrid = (bool)$settings->get('meilisearch.rag.useHybrid', true)
            && trim((string)$settings->get('meilisearch.embedder.source', '')) !== '';
        $conversation = $options['conversation'] ?? null;
        if (!$conversation instanceof Conversation) {
            $conversation = Conversation::empty();
        }

        $event = new BeforeRagQueryEvent($question, array_merge([
            'perPage' => $maxHits,
            'hybrid' => $useHybrid,
        ], $options));
        $this->eventDispatcher->dispatch($event);

        $searchResult = $this->searchService->search($site, $event->question, $event->options);
        $hits = array_values(array_slice($searchResult->hits, 0, $maxHits));
        if ($hits === []) {
            yield RagStreamChunk::noContext();
            return;
        }

        // Emit sources first so the UI has something to render while
        // tokens start streaming in.
        yield RagStreamChunk::sources($hits);

        $systemPrompt = (string)$settings->get('meilisearch.rag.systemPrompt', '');
        $currentTurnMessages = $this->promptBuilder->build(
            $site,
            $event->question,
            $hits,
            $systemPrompt,
            $maxChars,
        );
        $messages = $this->withConversation($currentTurnMessages, $conversation);

        $llmOptions = [
            'model' => (string)$settings->get('meilisearch.rag.model', ''),
            'apiKey' => (string)$settings->get('meilisearch.rag.apiKey', ''),
            'url' => (string)$settings->get('meilisearch.rag.url', ''),
            'temperature' => (float)$settings->get('meilisearch.rag.temperature', 0.2),
            // Tenant id for vendor-specific providers (currently Infomaniak).
            // Generic providers ignore it.
            'productId' => (string)$settings->get('meilisearch.infomaniak.productId', ''),
        ];

        $before = new BeforeLlmCallEvent($messages, $llmOptions);
        $this->eventDispatcher->dispatch($before);

        // BeforeLlmCallEvent listeners may short-circuit by setting a
        // cached response. Honor that path even when streaming — emit
        // the cached text as a single token chunk.
        if ($before->response !== null) {
            yield RagStreamChunk::token($before->response);
            $citedIds = $this->extractCitations($before->response, $hits);
            yield RagStreamChunk::done($before->response, $citedIds);
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, new RagAnswer(
                answer: trim($before->response),
                sources: $hits,
                citedIds: $citedIds,
                status: 'ok',
            )));
            return;
        }

        $accumulated = '';
        try {
            foreach ($provider->streamComplete($before->messages, $before->options) as $delta) {
                if ($delta === '') {
                    continue;
                }
                $accumulated .= $delta;
                yield RagStreamChunk::token($delta);
            }
        } catch (LlmException $e) {
            $this->logger?->error('RAG streaming failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            yield RagStreamChunk::failed($e->getMessage());
            return;
        }

        $citedIds = $this->extractCitations($accumulated, $hits);
        yield RagStreamChunk::done(trim($accumulated), $citedIds);

        $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, new RagAnswer(
            answer: trim($accumulated),
            sources: $hits,
            citedIds: $citedIds,
            status: 'ok',
        )));
    }

    /**
     * Splice the conversation history between the system prompt (first
     * message PromptBuilder emits) and the new user turn (everything
     * after). Caller (controller) is responsible for capping the history
     * via Conversation::withTurn() before passing it in here.
     *
     * @param list<array{role:string,content:string}> $currentTurnMessages
     * @return list<array{role:string,content:string}>
     */
    private function withConversation(array $currentTurnMessages, Conversation $conversation): array
    {
        if ($conversation->isEmpty()) {
            return $currentTurnMessages;
        }
        if ($currentTurnMessages === []) {
            return $conversation->toMessages();
        }
        // First element is the system prompt; the rest is the new user
        // turn. Insert history between them.
        $system = array_slice($currentTurnMessages, 0, 1);
        $rest = array_slice($currentTurnMessages, 1);
        return array_merge($system, $conversation->toMessages(), $rest);
    }

    /**
     * @param list<array<string,mixed>> $hits
     * @return list<string>
     */
    private function extractCitations(string $responseText, array $hits): array
    {
        $validIds = [];
        foreach ($hits as $hit) {
            if (isset($hit['id']) && (is_string($hit['id']) || is_int($hit['id']))) {
                $validIds[(string)$hit['id']] = true;
            }
        }
        if ($validIds === []) {
            return [];
        }

        $found = [];
        if (preg_match_all(self::CITATION_BLOCK_PATTERN, $responseText, $blocks) && isset($blocks[1])) {
            foreach ($blocks[1] as $inner) {
                if (!preg_match_all(self::CITATION_TOKEN_PATTERN, $inner, $tokens) || !isset($tokens[0])) {
                    continue;
                }
                foreach ($tokens[0] as $token) {
                    if (isset($validIds[$token]) && !in_array($token, $found, true)) {
                        $found[] = $token;
                    }
                }
            }
        }
        return $found;
    }
}

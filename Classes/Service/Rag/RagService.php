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
        private readonly QueryRewriter $queryRewriter,
        private readonly SuggestionGenerator $suggestionGenerator,
    ) {}

    /**
     * Assemble the LLM connection + sampling options from Site Settings.
     * Shared by ask()/askStreaming() and the conversational query rewrite.
     *
     * @return array<string,mixed>
     */
    private function buildLlmOptions(object $settings): array
    {
        $llmOptions = [
            'model' => (string)$settings->get('meilisearch.rag.model', ''),
            'apiKey' => (string)$settings->get('meilisearch.rag.apiKey', ''),
            'url' => (string)$settings->get('meilisearch.rag.url', ''),
            'temperature' => (float)$settings->get('meilisearch.rag.temperature', 0.2),
            'timeout' => (int)$settings->get('meilisearch.rag.timeout', 60),
            // Tenant id for vendor-specific providers (currently Infomaniak).
            // Generic providers ignore it.
            'productId' => (string)$settings->get('meilisearch.infomaniak.productId', ''),
        ];
        $maxTokens = (int)$settings->get('meilisearch.rag.maxTokens', 0);
        if ($maxTokens > 0) {
            $llmOptions['maxTokens'] = $maxTokens;
        }
        return $llmOptions;
    }

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

        $event = new BeforeRagQueryEvent($question, $this->mergeRetrievalOptions(
            $this->buildRetrievalOptions($site, $settings, $useHybrid, $maxHits),
            $options,
        ));
        $this->eventDispatcher->dispatch($event);

        $llmOptions = $this->buildLlmOptions($settings);
        // Fold conversation history into the *retrieval* query so a follow-up
        // ("und der Preis?") searches for the right subject. Retrieval only —
        // $event->question (the user's actual wording) still drives the answer
        // prompt below and the analytics, so nothing user-visible changes.
        $retrievalQuestion = $this->queryRewriter->rewrite($provider, $settings, $conversation, $event->question, $llmOptions);

        $searchResult = $this->searchService->search($site, $retrievalQuestion, $event->options);
        $hits = array_values(array_slice($searchResult->hits, 0, $maxHits));
        if ($hits === []) {
            $hits = $this->retrieveWithFallbacks($site, $retrievalQuestion, $event->options, $maxHits);
        }
        if ($hits === []) {
            $answer = RagAnswer::noContext();
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, $answer, $site, $this->resolveLanguageId($options)));
            return $answer;
        }

        $systemPrompt = (string)$settings->get('meilisearch.rag.systemPrompt', '');
        $currentTurnMessages = $this->promptBuilder->build(
            $site,
            $event->question,
            $hits,
            $systemPrompt,
            $maxChars,
            $this->resolveLanguageId($options),
        );

        // Splice prior turns between the system prompt and the current
        // user message. PromptBuilder always emits [system, user]; we
        // insert the conversation history between those two so the LLM
        // sees: system → history → current user (with fresh context).
        $messages = $this->withConversation($currentTurnMessages, $conversation);

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
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, $answer, $site, $this->resolveLanguageId($options)));
            return $answer;
        }

        $citedIds = $this->extractCitations($responseText, $hits);
        $answer = new RagAnswer(
            answer: trim($responseText),
            sources: $hits,
            citedIds: $citedIds,
            status: 'ok',
        );
        $after = new AfterRagAnswerEvent($event->question, $answer, $site, $this->resolveLanguageId($options));
        $this->eventDispatcher->dispatch($after);
        $final = $after->answer;
        // Decision-support suggestions (followup / refine / recommend),
        // rendered as buttons under the answer. Generated from the final
        // answer + sources; returns [] when disabled or on any error, so it
        // never blocks the answer.
        return $final->withSuggestions(
            $this->suggestionGenerator->generate($provider, $settings, $event->question, $final, $llmOptions),
        );
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

        $event = new BeforeRagQueryEvent($question, $this->mergeRetrievalOptions(
            $this->buildRetrievalOptions($site, $settings, $useHybrid, $maxHits),
            $options,
        ));
        $this->eventDispatcher->dispatch($event);

        $llmOptions = $this->buildLlmOptions($settings);
        // Conversational rewrite for retrieval only (see ask()); the streamed
        // answer still uses $event->question so the user's wording + history
        // drive the reply.
        $retrievalQuestion = $this->queryRewriter->rewrite($provider, $settings, $conversation, $event->question, $llmOptions);

        $searchResult = $this->searchService->search($site, $retrievalQuestion, $event->options);
        $hits = array_values(array_slice($searchResult->hits, 0, $maxHits));
        if ($hits === []) {
            // Reuse the same retrieval-fallback ladder ask() uses
            // (frequency → last → drop-leading-verb-token) so the
            // streaming RAG path doesn't degrade differently than
            // non-streaming on identical questions.
            $hits = $this->retrieveWithFallbacks($site, $retrievalQuestion, $event->options, $maxHits);
        }
        if ($hits === []) {
            yield RagStreamChunk::noContext();
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent(
                $event->question,
                RagAnswer::noContext(),
                $site,
                $this->resolveLanguageId($options),
            ));
            return;
        }

        // Emit sources first so the UI has something to render while
        // tokens start streaming in. Knowledge resources are part of the
        // hits (the LLM grounds in them) but the UI filters them out of
        // the user-visible "Sources" panel — see Rag/Ask.html.
        yield RagStreamChunk::sources($hits);

        $systemPrompt = (string)$settings->get('meilisearch.rag.systemPrompt', '');
        $currentTurnMessages = $this->promptBuilder->build(
            $site,
            $event->question,
            $hits,
            $systemPrompt,
            $maxChars,
            $this->resolveLanguageId($options),
        );
        $messages = $this->withConversation($currentTurnMessages, $conversation);

        $before = new BeforeLlmCallEvent($messages, $llmOptions);
        $this->eventDispatcher->dispatch($before);

        // BeforeLlmCallEvent listeners may short-circuit by setting a
        // cached response. Honor that path even when streaming — emit
        // the cached text as a single token chunk.
        if ($before->response !== null) {
            yield RagStreamChunk::token($before->response);
            $citedIds = $this->extractCitations($before->response, $hits);
            yield RagStreamChunk::done($before->response, $citedIds);
            $cachedAnswer = new RagAnswer(
                answer: trim($before->response),
                sources: $hits,
                citedIds: $citedIds,
                status: 'ok',
            );
            $cachedSuggestions = $this->suggestionGenerator->generate($provider, $settings, $event->question, $cachedAnswer, $llmOptions);
            if ($cachedSuggestions !== []) {
                yield RagStreamChunk::suggestions($cachedSuggestions);
            }
            yield RagStreamChunk::end();
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, $cachedAnswer, $site, $this->resolveLanguageId($options)));
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
            $this->eventDispatcher->dispatch(new AfterRagAnswerEvent(
                $event->question,
                RagAnswer::failed($e->getMessage()),
                $site,
                $this->resolveLanguageId($options),
            ));
            return;
        }

        $citedIds = $this->extractCitations($accumulated, $hits);
        yield RagStreamChunk::done(trim($accumulated), $citedIds);

        $answer = new RagAnswer(
            answer: trim($accumulated),
            sources: $hits,
            citedIds: $citedIds,
            status: 'ok',
        );
        // Decision-support suggestions, same generator as the non-streaming
        // ask(); emitted as a trailing frame so the streaming chat shows the
        // followup / refine / recommend buttons too.
        $suggestions = $this->suggestionGenerator->generate($provider, $settings, $event->question, $answer, $llmOptions);
        if ($suggestions !== []) {
            yield RagStreamChunk::suggestions($suggestions);
        }
        yield RagStreamChunk::end();
        $this->eventDispatcher->dispatch(new AfterRagAnswerEvent($event->question, $answer, $site, $this->resolveLanguageId($options)));
    }

    /**
     * Build the search-retrieval option set RAG uses to fetch grounding
     * context. Shared between ask() and askStreaming() so behaviour stays
     * consistent. Honours two RAG-specific tunables:
     *
     *  - meilisearch.rag.semanticRatio — per-RAG semantic-vs-keyword
     *    mix (defaults higher than the FE search; questions like "Wie
     *    gebe ich eine Lizenz frei?" need the vector retriever to bridge
     *    the morphological gap to a KR titled "Lizenzen freigeben").
     *  - meilisearch.rag.restrictToKnowledgeResources — when true, the
     *    retriever filters to `type = knowledge_resource`, keeping
     *    Tika-extracted file bodies from outranking the curated DITA
     *    grounding corpus.
     *
     * Caller-supplied $options merge OVER these defaults via the
     * surrounding array_merge in ask()/askStreaming(), so explicit
     * overrides (CLI flags, BeforeRagQueryEvent listeners) still win.
     *
     * @return array<string,mixed>
     */
    /**
     * Multi-stage retrieval fallback for the no-hits case. Tried in
     * order until one returns hits or all are exhausted:
     *   1) matchingStrategy=last (drop trailing tokens)
     *   2) drop the leading 1-2 tokens (catches verb-led questions
     *      where "gebe"/"siehe"/"nimm" at position 0 prevent
     *      Meilisearch from finding any match, since `last` never
     *      drops leading tokens and `frequency` drops the wrong ones).
     *
     * Skipped when the caller already passed matchingStrategy=last
     * (the loop's stage 1 is then a no-op duplicate) — stage 2 still
     * runs because the token-drop is orthogonal.
     *
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>
     */
    private function retrieveWithFallbacks(Site $site, string $question, array $options, int $maxHits): array
    {
        $primaryStrategy = (string)($options['matchingStrategy'] ?? '');
        $tried = [];
        if ($primaryStrategy !== 'last') {
            $tried[] = ['q' => $question, 'opts' => ['matchingStrategy' => 'last'] + $options];
        }
        // Token-drop variants. Pre-split on whitespace so "Was ist?" → ["Was","ist?"]
        // tokens count is the same as the human-readable count.
        $tokens = preg_split('/\s+/u', trim($question)) ?: [];
        if (\count($tokens) >= 3) {
            $tried[] = ['q' => implode(' ', \array_slice($tokens, 1)), 'opts' => ['matchingStrategy' => 'last'] + $options];
        }
        if (\count($tokens) >= 5) {
            $tried[] = ['q' => implode(' ', \array_slice($tokens, 2)), 'opts' => ['matchingStrategy' => 'last'] + $options];
        }
        foreach ($tried as $attempt) {
            $r = $this->searchService->search($site, $attempt['q'], $attempt['opts']);
            $hits = array_values(array_slice($r->hits, 0, $maxHits));
            if ($hits !== []) {
                return $hits;
            }
        }
        return [];
    }

    private function buildRetrievalOptions(Site $site, object $settings, bool $useHybrid, int $maxHits): array
    {
        $opts = [
            'perPage' => $maxHits,
            'hybrid' => $useHybrid,
            // Don't let the internal retrieval search show up in the
            // search analytics log — the RagAnalyticsLogger records the
            // RAG turn (question + status + cited count) separately.
            // Survives the fallback ladder because those retries reuse
            // this options array.
            '__skipAnalytics' => true,
            // Knowledge resources are the primary grounding corpus and
            // must be retrieved here even though they're hidden from
            // the public FE search results.
            'includeKnowledgeResources' => true,
            // Client-side stop-word stripping: defaults to ON because
            // long natural-language questions ("Wie kann ich die
            // Lizenzdatei automatisch importieren?") otherwise drown
            // the fach-tokens under common-word matches and Meilisearch
            // ranks irrelevant docs (e.g. "Raumbauteilen angrenzenden
            // Raum zuweisen") above the obvious hit ("Lizenzdatei
            // automatisch importieren"). Operator can override via
            // meilisearch.rag.stripStopWords when the stripped form
            // becomes too short for synonym expansion ("gebe frei" →
            // "freigeben") to fire.
            'stripStopWords' => (bool)$settings->get('meilisearch.rag.stripStopWords', true),
            // Drop the most frequent tokens before "last" Meilisearch would
            // otherwise drop the trailing ones. Important for question-shaped
            // queries where the first token is a verb form not present in
            // any KR title ("gebe", "wie") — "last" returns zero hits even
            // though the answer document is right there. Operator can
            // restore the Meilisearch default ("last") via the setting.
            'matchingStrategy' => (string)$settings->get('meilisearch.rag.matchingStrategy', 'frequency'),
        ];
        // RAG-specific stop-words override: narrow the strip set down to
        // generic question / function words. Without this override the
        // FE-tuned index-side stopWords would also nuke brand / domain
        // tokens (a vendor's brand name, a product family, a domain
        // verb) and the query goes empty for short product questions
        // like "Was ist <BrandName> <ProductName>?".
        $ragStopWords = $settings->get('meilisearch.rag.stopWords', null);
        if (is_array($ragStopWords) && $ragStopWords !== []) {
            $opts['stopWords'] = array_values(array_map('strval', $ragStopWords));
        }
        // Default 0.3 mirrors the settings.definitions.yaml default;
        // hard-coding it here too means sites that never opt into a
        // Site Set still get the RAG-tuned ratio (without it, the
        // embedder.semanticRatio fallback at 0.5 in SearchService
        // is too aggressive — generic semantic neighbours drown the
        // actually-relevant KR title).
        $semanticRatio = $settings->get('meilisearch.rag.semanticRatio', 0.3);
        if ($semanticRatio !== null && is_numeric($semanticRatio)) {
            $opts['semanticRatio'] = (float)$semanticRatio;
        }
        if ((bool)$settings->get('meilisearch.rag.restrictToKnowledgeResources', false)) {
            $opts['filters'] = ['type' => ['knowledge_resource']];
        }
        return $opts;
    }

    /**
     * Merge RAG defaults with caller-supplied options. Plain top-level keys
     * follow array_merge semantics (caller wins). The `filters` key needs
     * special handling — the controller's language + access filters must
     * combine with the RAG `type` filter, not replace it. Keys collected
     * under `filters` are unioned; `__rawFilters` (used by
     * AccessControlFilter) keeps both sides' raw expressions.
     *
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $caller
     * @return array<string,mixed>
     */
    private function mergeRetrievalOptions(array $defaults, array $caller): array
    {
        $defaultFilters = isset($defaults['filters']) && is_array($defaults['filters']) ? $defaults['filters'] : [];
        $callerFilters = isset($caller['filters']) && is_array($caller['filters']) ? $caller['filters'] : [];
        unset($defaults['filters'], $caller['filters']);
        $merged = array_merge($defaults, $caller);
        if ($defaultFilters !== [] || $callerFilters !== []) {
            $rawFilters = array_merge(
                (array)($defaultFilters['__rawFilters'] ?? []),
                (array)($callerFilters['__rawFilters'] ?? []),
            );
            unset($defaultFilters['__rawFilters'], $callerFilters['__rawFilters']);
            $filters = array_merge($defaultFilters, $callerFilters);
            if ($rawFilters !== []) {
                $filters['__rawFilters'] = $rawFilters;
            }
            $merged['filters'] = $filters;
        }
        return $merged;
    }

    /**
     * Pick the explicit `language` option (int site-language id) from the
     * caller. Returns null if the caller didn't pass one; PromptBuilder
     * then falls back to inferring from hits[0]. Accept ints and numeric
     * strings since route params often arrive as strings.
     *
     * @param array<string,mixed> $options
     */
    private function resolveLanguageId(array $options): ?int
    {
        if (!array_key_exists('language', $options)) {
            return null;
        }
        $value = $options['language'];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int)$value;
        }
        return null;
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

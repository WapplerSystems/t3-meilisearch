<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\CitationRenderer;
use WapplerSystems\Meilisearch\Service\Rag\ConversationStore;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\Rag\RagStreamChunk;
use WapplerSystems\Meilisearch\Service\Rag\Turn;

/**
 * Server-Sent-Events endpoint for streaming RAG responses.
 *
 * Mapped to a fixed path so it's easy to whitelist in webserver
 * configs that need to disable response buffering. The path is
 * deliberately namespaced under `_ws_meilisearch/` so it doesn't
 * collide with normal page slugs.
 *
 * Wire-format: standard SSE. Each frame is `event: <type>\ndata: <json>\n\n`,
 * with `<type>` matching the RagStreamChunk constants. Clients use
 * EventSource and addEventListener('token', …) / 'sources', 'done', etc.
 *
 * Buffering caveat: SSE only works if every layer between PHP and the
 * browser flushes immediately. With Nginx, set `proxy_buffering off`
 * or `fastcgi_buffering off`; with Apache + mod_proxy_fcgi, set
 * `flushpackets=on`. The `X-Accel-Buffering: no` header below tells
 * Nginx so for the response — it works automatically with the recent
 * defaults but is documented explicitly for safety.
 */
final class RagStreamMiddleware implements MiddlewareInterface
{
    private const PATH = '/_ws_meilisearch/rag/stream';

    public function __construct(
        private readonly RagService $ragService,
        private readonly ConversationStore $conversationStore,
        private readonly AccessControlFilter $accessControlFilter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Answered both bare (/_ws_meilisearch/rag/stream) and under any of
        // the site's language bases (/de/_ws_meilisearch/rag/stream). The
        // prefixed form is what the rendered shell calls: this middleware runs
        // after typo3/cms-frontend/site, so a language base in the path is all
        // it takes for the core to hand us the resolved SiteLanguage — no
        // client-supplied language parameter to validate. The bare form stays
        // for backwards compatibility and simply retrieves unfiltered.
        $path = $request->getUri()->getPath();
        if (!str_ends_with($path, self::PATH)) {
            return $handler->handle($request);
        }
        $prefix = substr($path, 0, -strlen(self::PATH));

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $this->jsonError('site not resolved', 404);
        }
        // An unknown prefix is somebody else's route, not a malformed call to
        // ours — hand it back to the stack rather than answering with an error.
        if ($prefix !== '' && !$this->languageForPrefix($site, $prefix) instanceof SiteLanguage) {
            return $handler->handle($request);
        }

        $query = $request->getQueryParams();
        $question = trim((string)($query['q'] ?? ''));
        if ($question === '') {
            return $this->jsonError('q parameter is required', 400);
        }

        $conversation = $this->loadConversation($site, $request);
        // Pin the LLM answer language to the active FE language so the
        // model doesn't drift to English when context excerpts span
        // multiple languages — matches the non-streaming controller.
        $askOptions = ['conversation' => $conversation];
        $language = $request->getAttribute('language');
        // Belt and braces: if site resolution did not attach the language for
        // this non-page path, derive it from the base prefix we just matched.
        if (!$language instanceof SiteLanguage) {
            $language = $this->languageForPrefix($site, $prefix);
        }
        if ($language instanceof SiteLanguage) {
            $askOptions['language'] = $language->getLanguageId();
            $restrict = (bool)$site->getSettings()->get('meilisearch.restrictToCurrentLanguage', true);
            if ($restrict) {
                $askOptions['filters'] = ['language' => [$language->getLanguageId()]];
            }
        }
        // FE-access-control on retrieval: same filter the non-streaming
        // RagController applies. Restricted docs never reach the LLM,
        // never appear as citations.
        $existingFilters = isset($askOptions['filters']) && is_array($askOptions['filters']) ? $askOptions['filters'] : [];
        $askOptions['filters'] = $this->accessControlFilter->applyTo($existingFilters, $site, $request);
        $stream = $this->ragService->askStreaming($site, $question, $askOptions);

        $this->writeSseStream($stream, $site, $request, $question, $conversation);

        // Headers + body were already flushed directly to the client.
        // Returning NullResponse tells TYPO3 not to emit any additional
        // payload on top of what we already sent.
        return new NullResponse();
    }

    /**
     * Map a path prefix such as "/de" onto the SiteLanguage whose base carries
     * it. Trailing slashes are normalised because a language base is stored as
     * "/de/" while the prefix we cut off the request path has none.
     */
    private function languageForPrefix(Site $site, string $prefix): ?SiteLanguage
    {
        $needle = rtrim($prefix, '/');
        foreach ($site->getLanguages() as $language) {
            if (rtrim($language->getBase()->getPath(), '/') === $needle) {
                return $language;
            }
        }

        return null;
    }

    /**
     * @param iterable<RagStreamChunk> $stream
     */
    private function writeSseStream(
        iterable $stream,
        Site $site,
        ServerRequestInterface $request,
        string $question,
        Conversation $conversation,
    ): void {
        // Drop any output buffer the rest of the stack would otherwise
        // accumulate behind us — SSE needs each frame flushed immediately.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $this->sendSessionCookie($request, $site, $conversation);

        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        // Tell the browser an explicit retry interval if the connection drops.
        echo "retry: 5000\n\n";
        flush();

        $finalChunk = null;
        // The sources frame passes by before the answer; keep it so the turn
        // can store the documents the answer ends up citing.
        $sources = [];
        $suggestions = [];
        foreach ($stream as $chunk) {
            $this->emitFrame($chunk);
            if ($chunk->type === RagStreamChunk::TYPE_SOURCES) {
                $sources = (array)($chunk->data['sources'] ?? []);
            }
            // The suggestion frame trails the answer; keep it so the turn can
            // offer the same buttons again after a reload.
            if ($chunk->type === RagStreamChunk::TYPE_SUGGESTIONS) {
                $suggestions = (array)($chunk->data['suggestions'] ?? []);
            }
            // Both `done` (answered) and `clarify` (asked back) are terminal
            // states worth persisting as a turn.
            if ($chunk->type === RagStreamChunk::TYPE_DONE || $chunk->type === RagStreamChunk::TYPE_CLARIFY) {
                $finalChunk = $chunk;
            }
            if (connection_aborted()) {
                break;
            }
        }

        // Persist the conversation turn if we produced an answer or asked a
        // clarifying question. We can't piggy-back this on the controller (the
        // controller never runs for streaming requests), so the middleware
        // owns the write-back. A clarification turn is stored with its own
        // kind and the clarifying question as the assistant text, so the
        // user's reply resolves against it on the next turn.
        if ($finalChunk !== null && $this->conversationEnabled($site)) {
            $maxTurns = max(1, (int)$site->getSettings()->get('meilisearch.rag.conversation.maxTurns', 3));
            if ($finalChunk->type === RagStreamChunk::TYPE_CLARIFY) {
                // The choices of the clarifying question ride along as
                // suggestions of type `clarify`, the same rows the client
                // renders live, so a reload offers them again.
                $optionen = [];
                foreach ((array)($finalChunk->data['options'] ?? []) as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $optionen[] = [
                        'type' => 'clarify',
                        'label' => (string)($option['label'] ?? ''),
                        'value' => (string)($option['value'] ?? ''),
                    ];
                }
                $turn = new Turn(
                    $question,
                    (string)($finalChunk->data['question'] ?? ''),
                    [],
                    Turn::KIND_CLARIFICATION,
                    [],
                    $optionen,
                );
            } else {
                $citedIds = array_values(array_map('strval', (array)($finalChunk->data['citedIds'] ?? [])));
                $turn = new Turn(
                    $question,
                    (string)($finalChunk->data['answer'] ?? ''),
                    $citedIds,
                    Turn::KIND_ANSWER,
                    CitationRenderer::citationsFor($sources, $citedIds),
                    $suggestions,
                );
            }
            $conversation = $conversation->withTurn($turn, $maxTurns);
            $this->conversationStore->save($request, $this->sessionKey($site), $conversation);
        }
    }

    private function emitFrame(RagStreamChunk $chunk): void
    {
        try {
            $payload = json_encode($chunk->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            $payload = '{}';
        }
        echo 'event: ' . $chunk->type . "\n";
        echo 'data: ' . $payload . "\n\n";
        flush();
    }

    /**
     * Hand the frontend session cookie to the client before the stream starts.
     *
     * The conversation lives in the frontend user session. This middleware
     * flushes its own headers and answers with a NullResponse, which tells
     * TYPO3 the response is already sent — so the authentication middleware
     * can no longer append the Set-Cookie it would normally add. Measured
     * consequence before this: the session was written on the server and never
     * claimed by the browser, so every question opened a fresh anonymous
     * session (98 rows in fe_sessions on the local install), multi-turn memory
     * never worked on the streamed path, and the reset link — which renders
     * only when a stored conversation exists — could not appear at all.
     *
     * ConversationStore::establish() is what brings the session into being and
     * marks it to be announced; appendCookieToResponse then produces exactly
     * the cookie the core would have sent — name, path, domain, secure,
     * httponly and samesite all from the install's own configuration rather
     * than a hand-rolled guess. It is @internal, which is the price for
     * emitting a cookie outside the normal response cycle.
     *
     * A visitor who already has a session gets no new cookie, which is right.
     */
    private function sendSessionCookie(
        ServerRequestInterface $request,
        Site $site,
        Conversation $conversation,
    ): void {
        if (headers_sent() || !$this->conversationEnabled($site)) {
            return;
        }
        $user = $request->getAttribute('frontend.user');
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$user instanceof FrontendUserAuthentication || !$normalizedParams instanceof NormalizedParams) {
            return;
        }
        $this->conversationStore->establish($request, $this->sessionKey($site), $conversation);
        foreach ($user->appendCookieToResponse(new Response(), $normalizedParams)->getHeader('Set-Cookie') as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
    }

    private function loadConversation(Site $site, ServerRequestInterface $request): Conversation
    {
        if (!$this->conversationEnabled($site)) {
            return Conversation::empty();
        }
        return $this->conversationStore->load($request, $this->sessionKey($site));
    }

    private function conversationEnabled(Site $site): bool
    {
        return (bool)$site->getSettings()->get('meilisearch.rag.conversation.enabled', false);
    }

    private function sessionKey(Site $site): string
    {
        $key = trim((string)$site->getSettings()->get(
            'meilisearch.rag.conversation.sessionKey',
            'ws_meilisearch_rag_conversation',
        ));
        return $key !== '' ? $key : 'ws_meilisearch_rag_conversation';
    }

    private function jsonError(string $message, int $status): ResponseInterface
    {
        return new \TYPO3\CMS\Core\Http\JsonResponse(['error' => $message], $status);
    }
}

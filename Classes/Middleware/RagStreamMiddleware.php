<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
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
        if ($request->getUri()->getPath() !== self::PATH) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $this->jsonError('site not resolved', 404);
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

        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        // Tell the browser an explicit retry interval if the connection drops.
        echo "retry: 5000\n\n";
        flush();

        $finalChunk = null;
        foreach ($stream as $chunk) {
            $this->emitFrame($chunk);
            if ($chunk->type === RagStreamChunk::TYPE_DONE) {
                $finalChunk = $chunk;
            }
            if (connection_aborted()) {
                break;
            }
        }

        // Persist the conversation turn if we actually produced an
        // answer. We can't piggy-back this on the controller (the
        // controller never runs for streaming requests), so the
        // middleware owns the write-back.
        if ($finalChunk !== null && $this->conversationEnabled($site)) {
            $maxTurns = max(1, (int)$site->getSettings()->get('meilisearch.rag.conversation.maxTurns', 3));
            $turn = new Turn(
                $question,
                (string)($finalChunk->data['answer'] ?? ''),
                array_values(array_map('strval', (array)($finalChunk->data['citedIds'] ?? []))),
            );
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

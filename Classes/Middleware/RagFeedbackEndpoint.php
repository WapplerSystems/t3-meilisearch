<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRecorder;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRepository;

/**
 * Stores the visitor's thumbs up / down under a chat answer
 * (meilisearch.rag.feedback.enabled).
 *
 * Path:  POST /_ws_meilisearch/rag/feedback   (also under a language base)
 * Body:  turn=<32 hex digits>&rating=1|-1     (form-encoded or JSON)
 *
 * The turn key is handed out by the stream (frame "turn") and stored with the
 * protocol row; it is random and known only to the visitor who got the
 * answer, so it doubles as the permission to rate. The row is written only
 * once the stream has ended — a click in the second after the answer may
 * arrive first, answers 404 and is retried by RagStream.js.
 *
 * Responses: 204 stored · 404 no such turn (yet) · 400 malformed · 405 not POST
 */
final class RagFeedbackEndpoint implements MiddlewareInterface
{
    private const PATH = '/_ws_meilisearch/rag/feedback';

    public function __construct(
        private readonly ChatProtocolRecorder $recorder,
        private readonly ChatProtocolRepository $repository,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!str_ends_with(rtrim($request->getUri()->getPath(), '/'), self::PATH)) {
            return $handler->handle($request);
        }
        $site = $request->getAttribute('site');
        if (!$site instanceof Site || !$this->recorder->isFeedbackEnabled($site)) {
            return new JsonResponse(['error' => 'feedback disabled'], 404);
        }
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['error' => 'POST required'], 405);
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || $body === []) {
            $decoded = json_decode((string)$request->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $turnKey = strtolower(trim((string)($body['turn'] ?? '')));
        $rating = (int)($body['rating'] ?? 0);
        if (preg_match(ChatProtocolRecorder::TURN_KEY_PATTERN, $turnKey) !== 1 || !in_array($rating, [-1, 1], true)) {
            return new JsonResponse(['error' => 'turn and rating (1 or -1) required'], 400);
        }

        return $this->repository->rate($site->getIdentifier(), $turnKey, $rating)
            ? new JsonResponse(null, 204)
            : new JsonResponse(['error' => 'unknown turn'], 404);
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpServer;
use WapplerSystems\Meilisearch\Mcp\McpTokenRepository;

/**
 * MCP endpoint (Streamable HTTP, stateless) at <site base>/_ws_meilisearch/mcp.
 *
 * Off unless the site sets meilisearch.mcp.enabled. Every request needs
 * `Authorization: Bearer <token>`, issued in the backend tab "MCP-Zugänge";
 * the token decides which tools exist for the caller and on which sites.
 * Only POST is served: the server never pushes, so the optional GET event
 * stream of the transport is answered with 405 as the spec allows.
 *
 * A request with an Origin header from a foreign host is refused — MCP
 * requires it against DNS rebinding, where a web page in a browser talks to
 * the endpoint. Claude Code / Desktop / API send no Origin at all.
 */
final class McpEndpoint implements MiddlewareInterface
{
    private const PATH = '/_ws_meilisearch/mcp';
    private const MAX_BODY = 1048576;

    public function __construct(
        private readonly McpServer $server,
        private readonly McpTokenRepository $tokens,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (rtrim($request->getUri()->getPath(), '/') !== self::PATH) {
            return $handler->handle($request);
        }
        $site = $request->getAttribute('site');
        if (!$site instanceof Site || !(bool)$site->getSettings()->get('meilisearch.mcp.enabled', false)) {
            return $handler->handle($request);
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return (new Response('php://temp', 405))->withHeader('Allow', 'POST');
        }
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== $request->getUri()->getHost()) {
            return $this->rpcError(null, -32600, 'Origin not allowed', 403);
        }

        $client = null;
        if (preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m) === 1) {
            $client = $this->tokens->authenticate($m[1]);
        }
        if ($client === null || !$client->allowsSite($site->getIdentifier())) {
            return $this->rpcError(null, -32001, 'Unauthorized', 401)
                ->withHeader('WWW-Authenticate', 'Bearer realm="ws_meilisearch"');
        }

        $body = (string)$request->getBody();
        if (strlen($body) > self::MAX_BODY) {
            return $this->rpcError(null, -32600, 'Request too large', 413);
        }
        try {
            $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->rpcError(null, -32700, 'Parse error', 400);
        }
        // JSON-RPC batches were removed from MCP in 2025-06-18; one message
        // per request keeps the stateless handling simple.
        if (!is_array($message) || array_is_list($message)) {
            return $this->rpcError(null, -32600, 'Invalid Request', 400);
        }

        $response = $this->server->handle($message, new McpContext($site, $client, $request));
        if ($response === null) {
            return new Response('php://temp', 202);
        }

        return new JsonResponse($response, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function rpcError(mixed $id, int $code, string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}

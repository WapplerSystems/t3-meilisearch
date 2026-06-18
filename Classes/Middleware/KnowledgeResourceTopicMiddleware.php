<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * Serves the DITA-OT XHTML help corpus at /hilfe/<path> directly from
 * the project root (outside public/), so the topic HTMLs + figures aren't
 * routed through TYPO3's rendering stack and don't leak the on-disk path.
 *
 * Path layout served:
 *   /hilfe/               → index.html
 *   /hilfe/de/topics/X.html
 *   /hilfe/de/figures/X.png
 *   /hilfe/commonltr.css  etc.
 *
 * Configured per site via `meilisearch.knowledgeResource.sourceRoot` (absolute or
 * relative to project root). Without configuration the middleware
 * short-circuits and lets normal TYPO3 routing handle the request — the
 * extension ships no default path on purpose so a fresh install never
 * exposes filesystem locations from any host project.
 * Path traversal is rejected at request time; only the configured root
 * + its descendants can be reached.
 *
 * MIME types are derived from the file extension — a small whitelist
 * keeps surprises out (no PHP execution, no fileadmin access).
 */
final class KnowledgeResourceTopicMiddleware implements MiddlewareInterface
{
    private const URL_PREFIX = '/hilfe';

    /** @var array<string, string> Whitelisted file extensions → Content-Type */
    private const MIME_MAP = [
        'html' => 'text/html; charset=UTF-8',
        'htm'  => 'text/html; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'xml'  => 'application/xml; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== self::URL_PREFIX && !str_starts_with($path, self::URL_PREFIX . '/')) {
            return $handler->handle($request);
        }

        $relative = substr($path, strlen(self::URL_PREFIX));
        $relative = ltrim($relative, '/');
        if ($relative === '' || str_ends_with($relative, '/')) {
            $relative .= 'index.html';
        }

        // Reject path traversal — must not contain ../ or null bytes.
        if (str_contains($relative, "\0") || str_contains($relative, '..')) {
            return new Response('php://memory', 400, [], 'Bad request');
        }

        $sourceRoot = $this->resolveSourceRoot($request);
        $absoluteSource = realpath($sourceRoot);
        if ($absoluteSource === false || !is_dir($absoluteSource)) {
            // Configuration error or corpus not deployed — let TYPO3 handle
            // it as a normal 404 (or a friendly error page) instead of
            // returning bare 500 from the middleware.
            return $handler->handle($request);
        }

        $candidate = realpath($absoluteSource . '/' . $relative);
        if ($candidate === false
            || !is_file($candidate)
            || !str_starts_with($candidate, $absoluteSource . DIRECTORY_SEPARATOR)
        ) {
            return new HtmlResponse(
                '<h1>404</h1><p>Help topic not found.</p>',
                404,
                ['content-type' => 'text/html; charset=UTF-8'],
            );
        }

        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $contentType = self::MIME_MAP[$ext] ?? null;
        if ($contentType === null) {
            return new HtmlResponse('<h1>415</h1><p>Unsupported media type.</p>', 415);
        }

        $body = new Stream('php://temp', 'r+');
        $body->write((string)file_get_contents($candidate));
        $body->rewind();
        return (new Response())
            ->withStatus(200)
            ->withHeader('content-type', $contentType)
            ->withHeader('cache-control', 'public, max-age=3600')
            ->withBody($body);
    }

    /**
     * Resolve the on-disk root of the DITA corpus from the active site's
     * settings (`meilisearch.knowledgeResource.sourceRoot`). Returns an empty
     * string when nothing is configured — caller treats that as
     * "feature disabled" and passes the request through.
     */
    private function resolveSourceRoot(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        if ($site === null || !method_exists($site, 'getSettings')) {
            return '';
        }
        $relative = trim((string)$site->getSettings()->get('meilisearch.knowledgeResource.sourceRoot', ''));
        if ($relative === '') {
            return '';
        }
        // Absolute path → use as-is. Relative → resolve against project root.
        if (str_starts_with($relative, '/')) {
            return rtrim($relative, '/');
        }
        return rtrim(Environment::getProjectPath(), '/') . '/' . trim($relative, '/');
    }
}
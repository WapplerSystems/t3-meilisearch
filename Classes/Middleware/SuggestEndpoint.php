<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\SearchService;

/**
 * Live-suggestion endpoint backing the JS autocomplete dropdown shipped
 * with the search frontend. The client fires a fetch on every keystroke
 * (debounced to ~150ms), this middleware runs a short bounded search
 * against the current site's index and returns just the fields the
 * dropdown needs — keeping the payload tiny so each keystroke stays
 * snappy even over slow connections.
 *
 * Path:  /_ws_meilisearch/suggest?q=…
 *
 * Response: {
 *   "totalHits": 17,
 *   "hits": [
 *     {"id": "pages-42", "title": "…", "type": "page", "uid": 42, "publicUrl": null},
 *     {"id": "file-7",   "title": "…", "type": "file", "uid": 7,  "publicUrl": "fileadmin/x.pdf"},
 *     …
 *   ]
 * }
 *
 * Hits use the unified-index doc shape, so the dropdown can render the
 * same per-type badge + link logic the Search/Result partial already
 * uses for the full results page.
 */
final class SuggestEndpoint implements MiddlewareInterface
{
    private const PATH = '/_ws_meilisearch/suggest';
    private const LIMIT = 5;
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== self::PATH) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return new JsonResponse(['hits' => [], 'totalHits' => 0]);
        }

        $params = $request->getQueryParams();
        $query = trim((string)($params['q'] ?? ''));
        if ($query === '') {
            return new JsonResponse(['hits' => [], 'totalHits' => 0]);
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        // The suggest endpoint deliberately runs the keyword path even
        // when an embedder is configured. Live dropdowns benefit from
        // exact-prefix matches, which the keyword retriever is better at
        // than the semantic one for partial-token input ("sas" should
        // suggest "saskatchewan", not its nearest vector neighbour).
        $result = $this->searchService->search($site, $query, [
            'perPage' => self::LIMIT,
            'page' => 1,
            'hybrid' => false,
        ]);

        $hits = [];
        foreach ($result->hits as $hit) {
            $hits[] = [
                'id' => (string)($hit['id'] ?? ''),
                'title' => (string)($hit['title'] ?? ''),
                'type' => (string)($hit['type'] ?? ''),
                'uid' => (int)($hit['uid'] ?? 0),
                'language' => (int)($hit['language'] ?? 0),
                'publicUrl' => isset($hit['publicUrl']) && $hit['publicUrl'] !== ''
                    ? (string)$hit['publicUrl']
                    : null,
            ];
        }

        return new JsonResponse([
            'hits' => $hits,
            'totalHits' => $result->totalHits,
        ]);
    }
}

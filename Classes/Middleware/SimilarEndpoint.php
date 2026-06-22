<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Service\SimilarDocumentsService;

/**
 * AJAX endpoint backing "Related content" widgets. Fetches the top-N
 * documents semantically similar to a given source doc id and returns
 * them as JSON so FE templates / SPA components / hilfecenter widgets
 * can render the list however they want.
 *
 * Path:  /_ws_meilisearch/similar?id=help-123&limit=5&types=knowledge_resource,page
 *
 * Response: {
 *   "hits": [
 *     {"id": "help-3684", "title": "…", "type": "knowledge_resource", "uri": "…", "imageUrl": "…", "abstract": "…"},
 *     …
 *   ],
 *   "sourceId": "help-123"
 * }
 *
 * Language scoping mirrors the suggest endpoint: visitors on /de/
 * only see DE-overlay siblings, plus docs whose detected
 * contentLanguage matches the site language. FE access control
 * applies — private docs never leak through a sibling lookup.
 */
final class SimilarEndpoint implements MiddlewareInterface
{
    private const PATH = '/_ws_meilisearch/similar';
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly SimilarDocumentsService $similarDocuments,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        // Accept both the bare path and the language-base-prefixed
        // variant (`/de/_ws_meilisearch/similar`) — matches what the FE
        // JS resolves relative to the page it lives on.
        if ($path !== self::PATH && !str_ends_with(rtrim($path, '/'), self::PATH)) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return new JsonResponse(['hits' => [], 'sourceId' => '']);
        }

        $params = $request->getQueryParams();
        $sourceId = trim((string)($params['id'] ?? ''));
        if ($sourceId === '') {
            return new JsonResponse(['hits' => [], 'sourceId' => '']);
        }

        $limit = (int)($params['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0 || $limit > self::MAX_LIMIT) {
            $limit = self::DEFAULT_LIMIT;
        }

        // `types` is comma-separated; empty / missing means "any type".
        // Useful default for help-center widgets: types=knowledge_resource
        // → never suggest a sys_file or news doc as a related KR.
        $types = [];
        $rawTypes = trim((string)($params['types'] ?? ''));
        if ($rawTypes !== '') {
            $types = array_values(array_filter(array_map('trim', explode(',', $rawTypes))));
        }

        $language = $request->getAttribute('language');
        $iso = '';
        $languageId = null;
        if ($language instanceof SiteLanguage) {
            $languageId = $language->getLanguageId();
            // v14 path — see feedback_v14_sitelanguage_locale.md
            $iso = strtolower((string)$language->getLocale()->getLanguageCode());
        }

        $hits = $this->similarDocuments->findSimilar($site, $sourceId, [
            'limit' => $limit,
            'types' => $types,
            'language' => $languageId,
            'contentLanguageIso' => $iso,
            'accessRequest' => $request,
        ]);

        return new JsonResponse([
            'hits' => $hits,
            'sourceId' => $sourceId,
        ]);
    }
}

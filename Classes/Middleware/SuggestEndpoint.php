<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
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
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly AccessControlFilter $accessControlFilter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Accept both the bare path and the language-base-prefixed
        // variant (`/de/_ws_meilisearch/suggest`). The frontend JS
        // resolves the endpoint URL relative to the page it lives on,
        // so multi-language sites with `/de/`, `/en/` etc. as language
        // bases will hit the prefixed form.
        $path = $request->getUri()->getPath();
        if ($path !== self::PATH && !str_ends_with(rtrim($path, '/'), self::PATH)) {
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

        // Scope to the active site language. Without this, a sys_file
        // indexed under 8 language overlays appears 8× — same title,
        // same uid — and fills the LIMIT=5 dropdown with duplicates.
        // The full search controller has the same guard behind the
        // `restrictToCurrentLanguage` setting; for the suggest dropdown
        // the per-language scope is the only sensible default — a
        // visitor on /de/ never wants English-only suggestions to
        // dilute the list. Pull the over-fetch + dedupe still happens
        // below as a defense against same-language metadata clones.
        $filters = [];
        // Resolve the site language even when the dropdown calls the bare
        // /_ws_meilisearch/suggest path (e.g. an auto-attached header input
        // outside any language base): TYPO3 then leaves the 'language'
        // request attribute empty, so without this the count would span all
        // language overlays and read far higher than the language-scoped
        // results list (the reported "preview shows many more hits").
        $language = $this->resolveSiteLanguage($request, $site);
        if ($language instanceof SiteLanguage) {
            $filters['language'] = [(string)$language->getLanguageId()];
        }
        // FE-access-control: hide docs whose accessGroups don't match
        // the visitor's effective groupIds. Public docs (accessGroups
        // empty) always pass through.
        $filters = $this->accessControlFilter->applyTo($filters, $site, $request);

        // Page-subtree scope: keep suggestions inside the same subtree as the
        // scoped search box (filters on the `rootline` index field).
        $scope = max(0, (int)($request->getQueryParams()['scope'] ?? 0));
        if ($scope > 0) {
            $filters['__rawFilters'][] = 'rootline = ' . $scope;
        }

        $settings = $site->getSettings();
        $limit = max(1, (int)$settings->get('meilisearch.suggest.limit', self::LIMIT));
        $groupByType = (bool)$settings->get('meilisearch.suggest.groupByType', false);
        $perTypeLimit = max(1, (int)$settings->get('meilisearch.suggest.perTypeLimit', 3));
        // Ordered allow-list of types for the grouped dropdown. Empty =
        // every type that surfaces, in first-seen (relevance) order.
        $typeOrder = [];
        foreach ((array)$settings->get('meilisearch.suggest.types', []) as $t) {
            if (is_string($t) && $t !== '') {
                $typeOrder[] = $t;
            }
        }
        // Over-fetch headroom: grouped mode needs enough rows per type
        // (after dedupe) to fill each section up to perTypeLimit.
        $overFetch = $groupByType
            ? max($limit, $perTypeLimit * max(count($typeOrder), 4)) * 3
            : $limit * 4;

        // The suggest endpoint deliberately runs the keyword path even
        // when an embedder is configured. Live dropdowns benefit from
        // exact-prefix matches, which the keyword retriever is better at
        // than the semantic one for partial-token input ("sas" should
        // suggest "saskatchewan", not its nearest vector neighbour).
        // Over-fetch so the post-dedupe still has enough rows to fill
        // the LIMIT — same uid+type can still surface twice on metadata
        // duplicates within one language.
        $result = $this->searchService->search($site, $query, [
            'perPage' => $overFetch,
            'page' => 1,
            'hybrid' => false,
            'filters' => $filters,
            // Tag for SearchAnalyticsLogger so the BE analytics tab
            // can show suggest-probe volume separately from real
            // search-result-page hits. Underscore-prefixed → never
            // passed to Meilisearch.
            '__analyticsSource' => 'suggest',
        ]);

        // Build the LanguageService once for the active site language so
        // type-label lookups are O(1) per hit instead of constructing
        // the service per call. Localizes badges in the dropdown to
        // match the FE search results partial.
        $languageService = $this->languageServiceFactory->createFromSiteLanguage(
            $language instanceof SiteLanguage ? $language : $site->getDefaultLanguage(),
        );

        $hits = [];
        $seen = [];
        foreach ($result->hits as $hit) {
            $type = (string)($hit['type'] ?? '');
            $uid = (int)($hit['uid'] ?? 0);
            // Dedupe by (type, uid). Same source record indexed under
            // multiple language overlays — or duplicate sys_file_metadata
            // rows on the same uid — must not flood the dropdown.
            $key = $type . ':' . $uid;
            if ($uid > 0 && isset($seen[$key])) {
                continue;
            }
            if ($uid > 0) {
                $seen[$key] = true;
            }
            // Badges an AfterSearchEvent listener attached (release, product,
            // edition …). They ride along so rows that would otherwise read
            // alike stay distinguishable: a documentation set carrying one
            // topic per product and release answers "Heizlast" with three
            // different pages of that exact title, and a dropdown of five
            // slots showing "Heizlast" three times helps nobody.
            $badges = [];
            foreach ((array)($hit['badges'] ?? []) as $badge) {
                if (!is_array($badge)) {
                    continue;
                }
                $label = trim((string)($badge['label'] ?? ''));
                if ($label !== '') {
                    $badges[] = $label;
                }
            }
            // Same title and same badges: nothing tells these two apart on
            // screen, so showing both is noise. Keep the better-ranked one.
            $shown = mb_strtolower(trim((string)($hit['title'] ?? '')) . '|' . implode(',', $badges));
            if ($shown !== '|' && isset($seen[$shown])) {
                continue;
            }
            $seen[$shown] = true;
            // Files store their URL as `publicUrl`, pages/news/knowledge_resource
            // store it as `uri` — fall back to the alternate field so every
            // hit in the dropdown becomes clickable. Strip the fragment
            // (`#c123`) the EXT:index pipeline appends to page URIs since the
            // dropdown wants the canonical page link, not a deep anchor.
            $url = (string)($hit['publicUrl'] ?? '');
            if ($url === '') {
                $url = (string)($hit['uri'] ?? '');
            }
            if ($url !== '' && ($hashPos = strpos($url, '#')) !== false) {
                $url = substr($url, 0, $hashPos);
            }
            // Pre-resolve the type badge label via the shared XLF so the
            // dropdown JS doesn't need its own English-only map. Unknown
            // types fall back to the raw key so an admin can still tell
            // what they are.
            $typeLabel = $type !== ''
                ? (function (string $t) use ($languageService): string {
                    $translated = $languageService->sL(
                        'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang.xlf:facet.value.type.' . $t,
                    );
                    return $translated !== '' ? $translated : $t;
                })($type)
                : '';
            // Image files carry a dedicated search token: the dropdown links
            // to the results page with q=<searchToken> (narrows to this one
            // image) instead of opening the file directly.
            $searchToken = (string)($hit['searchToken'] ?? '');
            $hits[] = [
                'id' => (string)($hit['id'] ?? ''),
                'title' => (string)($hit['title'] ?? ''),
                'type' => $type,
                'typeLabel' => $typeLabel,
                'uid' => $uid,
                'language' => (int)($hit['language'] ?? 0),
                'publicUrl' => $url !== '' ? $url : null,
                'searchToken' => $searchToken !== '' ? $searchToken : null,
                'badges' => $badges,
            ];
            // Grouped mode needs the full deduped set (bounded by the
            // over-fetch above) to fill every section; flat mode stops
            // as soon as it has the overall limit.
            if (!$groupByType && count($hits) >= $limit) {
                break;
            }
        }

        if (!$groupByType) {
            return new JsonResponse([
                'hits' => array_slice($hits, 0, $limit),
                'totalHits' => $result->totalHits,
            ]);
        }

        // Group by type. Section order: configured types first (in the
        // operator's order), then any remaining surfaced types in
        // relevance order. Each section capped at perTypeLimit.
        $byType = [];
        foreach ($hits as $h) {
            $byType[$h['type']][] = $h;
        }
        $orderedTypes = $typeOrder;
        foreach (array_keys($byType) as $t) {
            if (!in_array($t, $orderedTypes, true)) {
                $orderedTypes[] = $t;
            }
        }
        $groups = [];
        $flat = [];
        foreach ($orderedTypes as $t) {
            if (empty($byType[$t])) {
                continue;
            }
            $groupHits = array_slice($byType[$t], 0, $perTypeLimit);
            $groups[] = [
                'type' => $t,
                'label' => (string)($groupHits[0]['typeLabel'] ?? $t),
                'hits' => $groupHits,
            ];
            foreach ($groupHits as $gh) {
                $flat[] = $gh;
            }
        }

        return new JsonResponse([
            'grouped' => true,
            'groups' => $groups,
            // Flat concatenation kept for the empty-check + any consumer
            // that ignores grouping.
            'hits' => $flat,
            'totalHits' => $result->totalHits,
        ]);
    }

    /**
     * Resolve the site language for scoping the suggestion query.
     *
     * Prefers the routed 'language' request attribute; when the dropdown
     * calls the bare /_ws_meilisearch/suggest path (no language base, e.g.
     * an auto-attached header input) that attribute is absent, so we recover
     * the language from the Referer (the page the search box sits on) by the
     * longest-matching language base path, finally falling back to the site
     * default. This keeps the suggest count consistent with the language-
     * scoped results list.
     */
    private function resolveSiteLanguage(ServerRequestInterface $request, Site $site): ?SiteLanguage
    {
        $language = $request->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return $language;
        }

        $refererPath = (string)(parse_url($request->getHeaderLine('Referer'), PHP_URL_PATH) ?: '');
        if ($refererPath !== '') {
            $refererPath = '/' . ltrim($refererPath, '/');
            $best = null;
            $bestLen = -1;
            foreach ($site->getAllLanguages() as $candidate) {
                $base = '/' . trim($candidate->getBase()->getPath(), '/');
                $prefix = $base === '/' ? '/' : $base . '/';
                if ($prefix === '/' || str_starts_with($refererPath . '/', $prefix)) {
                    $len = strlen($prefix);
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $best = $candidate;
                    }
                }
            }
            if ($best instanceof SiteLanguage) {
                return $best;
            }
        }

        return $site->getDefaultLanguage();
    }
}

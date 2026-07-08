<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\SearchService;

final class SearchController extends ActionController
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly SiteFinder $siteFinder,
        private readonly SearchConfigurationProvider $configProvider,
        private readonly AccessControlFilter $accessControlFilter,
    ) {}

    /**
     * The 'site' request attribute is set by the SiteResolver middleware on the
     * raw PSR-7 request, but Extbase wraps the request before reaching the
     * controller and the attribute is not always preserved. Fall back to
     * resolving via the current page id through SiteFinder.
     */
    private function resolveSite(): ?Site
    {
        $site = $this->request->getAttribute('site');
        if ($site instanceof Site) {
            return $site;
        }
        $globalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($globalRequest !== null) {
            $site = $globalRequest->getAttribute('site');
            if ($site instanceof Site) {
                return $site;
            }
            $pageInfo = $globalRequest->getAttribute('frontend.page.information');
            if ($pageInfo !== null && method_exists($pageInfo, 'getId')) {
                try {
                    return $this->siteFinder->getSiteByPageId((int)$pageInfo->getId());
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    public function searchAction(string $q = '', int $scope = -1): ResponseInterface
    {
        $this->assignScopeVars($this->effectiveScope($scope));
        $this->view->assign('query', $q);
        return $this->htmlResponse();
    }

    /**
     * Effective page-subtree scope for this request.
     *
     * A visitor-provided `scope` param wins so the result chip can drop it:
     * >0 = explicit subtree, 0 = cleared (site-wide). The default -1 means
     * "not provided" and inherits the per-plugin FlexForm
     * settings.restrictToPageSubtree.
     */
    private function effectiveScope(int $scopeParam): int
    {
        if ($scopeParam >= 0) {
            return $scopeParam;
        }
        $raw = (string)($this->settings['restrictToPageSubtree'] ?? '');
        // group/pages stores "123" or legacy "pages_123" — pull the uid out.
        return preg_match('/\d+/', $raw, $m) === 1 ? (int)$m[0] : 0;
    }

    /**
     * Assign the scope view variables shared by search + results templates:
     * the effective uid (carried through forms/pagination as `scope`), the
     * overlaid page title for the placeholder/chip, and a boolean flag.
     */
    private function assignScopeVars(int $scopeUid): void
    {
        $this->view->assignMultiple([
            'scope' => $scopeUid,
            'scopePageUid' => $scopeUid,
            'scopePageTitle' => $scopeUid > 0 ? $this->resolvePageTitle($scopeUid) : '',
            'scopeActive' => $scopeUid > 0,
        ]);
    }

    /**
     * Language-overlaid title of a page (nav_title preferred). Empty when the
     * page is gone/inaccessible so the scope silently degrades to site-wide.
     */
    private function resolvePageTitle(int $uid): string
    {
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        $page = $pageRepository->getPage($uid, true);
        if ($page === []) {
            return '';
        }
        $navTitle = trim((string)($page['nav_title'] ?? ''));
        return $navTitle !== '' ? $navTitle : trim((string)($page['title'] ?? ''));
    }

    /**
     * @param array<string,array<int,string>> $filters
     */
    public function resultsAction(string $q = '', int $page = 1, array $filters = [], int $hybrid = 0, string $sort = '', int $scope = -1): ResponseInterface
    {
        if (strtoupper($this->request->getMethod()) === 'POST') {
            return $this->redirect('results', null, null, [
                'q' => $q,
                'page' => $page,
                'filters' => $filters,
                'hybrid' => $hybrid,
                'sort' => $sort,
                'scope' => $scope,
            ]);
        }

        $site = $this->resolveSite();
        if (!$site instanceof Site) {
            return $this->htmlResponse();
        }

        // Raw Meilisearch filter expressions are SERVER-BUILT only (access
        // control, language, scope). Never honour them from the request —
        // otherwise a crafted URL could inject arbitrary filters or a stale
        // scope would survive "remove filter". Strip before use; keep a
        // clean copy of the user's facet selections for building template
        // URLs (pagination / chip) so the server-side raw filters don't
        // leak into links and get replayed.
        unset($filters['__rawFilters']);
        $viewFilters = $filters;

        // Page-subtree scope (KB-style search). Effective uid inherits the
        // per-plugin FlexForm default unless the visitor cleared it via the
        // result chip (scope=0). Filters on the `rootline` index field
        // (list of ancestor page uids, contributed by EXT:linear_knowledge).
        $scopeUid = $this->effectiveScope($scope);
        $this->assignScopeVars($scopeUid);
        if ($scopeUid > 0) {
            $filters['__rawFilters'][] = 'rootline = ' . $scopeUid;
        }
        // Per-plugin TypoScript / FlexForm wins when explicitly set
        // (preserves operator overrides on existing site packages); otherwise
        // the Site-Settings defaults from meilisearch.frontend.perPage /
        // meilisearch.facets apply.
        $perPage = isset($this->settings['perPage']) && (int)$this->settings['perPage'] > 0
            ? (int)$this->settings['perPage']
            : $this->configProvider->defaultPerPage($site);
        $tsFacets = trim((string)($this->settings['facets'] ?? ''));
        if ($tsFacets !== '') {
            $facetList = array_values(array_filter(array_map('trim', explode(',', $tsFacets))));
        } else {
            $facetList = $this->configProvider->facetAttributes($site);
        }

        // Default sort: visitor sort wins; if absent, FlexForm's
        // settings.defaultSort wins; otherwise relevance (empty string).
        if ($sort === '') {
            $sort = trim((string)($this->settings['defaultSort'] ?? ''));
        }

        // Integrator switch: per-language search page hides the language
        // facet and force-filters results to the active site language.
        // Resolution: per-plugin FlexForm tri-state ('1' = on, '0' = off,
        // '' = inherit) wins over the site-level
        // meilisearch.restrictToCurrentLanguage flag. The fragment
        // middleware (which has no Extbase plugin context) only reads the
        // site flag — that's the legacy behaviour, intentional: a plugin
        // override doesn't propagate into the AJAX fragment of a different
        // page.
        $restrictFlexValue = $this->settings['restrictToCurrentLanguage'] ?? null;
        if ($restrictFlexValue === '1' || $restrictFlexValue === 1) {
            $restrictToLanguage = true;
        } elseif ($restrictFlexValue === '0' || $restrictFlexValue === 0) {
            $restrictToLanguage = false;
        } else {
            $restrictToLanguage = (bool)$site->getSettings()->get('meilisearch.restrictToCurrentLanguage', false);
        }
        if ($restrictToLanguage) {
            $currentLanguageId = $this->resolveCurrentLanguageId();
            if ($currentLanguageId !== null) {
                $filters['language'] = [$currentLanguageId];
            }
            // Layer the detected CONTENT language on top so a file
            // indexed under every overlay (file-X-l0 / -l1 / …) but
            // with German bytes only shows up on the German site, not
            // on the English one. Empty `contentLanguage` (detection
            // declined as low-confidence) is also accepted — we'd
            // rather over-show than hide a doc whose language we
            // genuinely don't know.
            $isoCode = $this->resolveCurrentLanguageIsoCode();
            if ($isoCode !== '') {
                $filters['__rawFilters'][] = sprintf(
                    '(contentLanguage = "%s" OR contentLanguage IS NULL OR contentLanguage IS EMPTY)',
                    str_replace('"', '\\"', $isoCode),
                );
            }
            $facetList = array_values(array_filter(
                $facetList,
                static fn (string $f): bool => $f !== 'language' && $f !== 'contentLanguage',
            ));
        }

        $hybridAvailable = trim((string)$site->getSettings()->get('meilisearch.embedder.source', '')) !== '';
        $useHybrid = $hybridAvailable && $hybrid === 1;

        // Sort: a single "field:direction" string from the FE, or empty
        // for relevance-only ranking. The SearchService accepts a list
        // — wrap the scalar; it handles "" / null gracefully.
        $sortOption = trim($sort);

        // FE-access-control: visitor only sees public docs + their
        // group-restricted docs. Use the global PSR-7 request because
        // Extbase wraps + strips request attributes by the time the
        // controller sees them.
        $accessReq = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $filters = $this->accessControlFilter->applyTo($filters, $site, $accessReq);

        $result = $this->searchService->search($site, $q, [
            'page' => max(1, $page),
            'perPage' => $perPage,
            'filters' => $filters,
            'facets' => $facetList,
            'hybrid' => $useHybrid,
            'sort' => $sortOption,
        ]);

        // Resolve the numeric language id of each hit to the site-
        // language label declared in the site config (e.g. "Deutsch"
        // for id 0, "English" for id 1). Doing it here keeps the
        // template trivial — `{hit.languageLabel}` instead of a
        // ViewHelper call per hit.
        //
        // displayPartial is pre-resolved from meilisearch.display.<type>
        // so the result region just renders `<f:render partial="{hit.displayPartial}"/>`
        // and dispatch happens by data instead of by template-side switch.
        $hits = [];
        foreach ($result->hits as $hit) {
            // Per-hit language hint is redundant when the whole result set is
            // restricted to one language — leave it empty so the partials
            // (which guard on {hit.languageLabel}) hide it.
            $hit['languageLabel'] = $restrictToLanguage
                ? ''
                : $this->resolveLanguageLabel($site, (int)($hit['language'] ?? 0));
            $hit['displayPartial'] = $this->configProvider->resolveDisplayPartial($site, (string)($hit['type'] ?? ''));
            $hits[] = $hit;
        }
        // Rebuild SearchResult with the enriched hits — DTO is readonly,
        // so a new instance with the same paging metadata is the way.
        $result = new \WapplerSystems\Meilisearch\Service\SearchResult(
            hits: $hits,
            totalHits: $result->totalHits,
            facets: $result->facets,
            page: $result->page,
            perPage: $result->perPage,
        );

        // Map of language-id → title so the language facet shows
        // "Deutsch" / "English" instead of the raw numeric id. Built once
        // from the site config; the template looks values up by string
        // key (e.g. {languageLabels.0}).
        $languageLabels = [];
        foreach ($site->getAllLanguages() as $language) {
            $languageLabels[(string)$language->getLanguageId()] = $language->getTitle();
        }

        // f:uri.action without pageUid falls back to the Extbase
        // plugin's configured defaultPid which is rarely the page the
        // visitor is currently on. Resolve the current page id from
        // the request so every pagination / facet link stays on the
        // same URL.
        $pageInfo = $this->request->getAttribute('frontend.page.information');
        $currentPageUid = $pageInfo !== null ? $pageInfo->getId() : (int)($GLOBALS['TSFE']->id ?? 0);

        $this->view->assignMultiple([
            'query' => $q,
            'page' => max(1, $page),
            'result' => $result,
            // Clean facet selections only — server-injected raw filters stay
            // out of pagination/chip URLs (see the unset above).
            'filters' => $viewFilters,
            'hybrid' => $useHybrid ? 1 : 0,
            'hybridAvailable' => $hybridAvailable,
            'sort' => $sortOption,
            'currentPageUid' => $currentPageUid,
            // Pre-built links so templates don't have to compute them.
            'sortOptions' => $this->configProvider->sortOptions($site) ?: $this->fallbackSortOptions(),
            // Indexed map for Facets.html to look up per-facet display
            // settings (label, widget, maxItems, collapsed, showCounts).
            // Keyed by attribute name to match the iteration variable in
            // the partial.
            'facetConfigs' => $this->indexFacetConfigs($site),
            'languageLabels' => $languageLabels,
            // Sliding window of page numbers to render in the pager (Fluid
            // has no range ViewHelper). Empty when there's only one page.
            'paginationWindow' => $this->paginationWindow($result->page, $result->getTotalPages()),
            'paginationFirst' => 0,
            'paginationLast' => 0,
        ] + $this->paginationBoundaries($result->page, $result->getTotalPages()));

        return $this->htmlResponse();
    }

    /**
     * Five-wide sliding window of page numbers around the current page,
     * clamped to [1, totalPages]. Empty when there's only one page.
     *
     * @return list<int>
     */
    private function paginationWindow(int $current, int $total): array
    {
        if ($total <= 1) {
            return [];
        }
        [$start, $end] = $this->paginationBoundsTuple($current, $total);
        return range($start, $end);
    }

    /**
     * @return array{paginationFirst:int,paginationLast:int}
     */
    private function paginationBoundaries(int $current, int $total): array
    {
        if ($total <= 1) {
            return ['paginationFirst' => 0, 'paginationLast' => 0];
        }
        [$start, $end] = $this->paginationBoundsTuple($current, $total);
        return ['paginationFirst' => $start, 'paginationLast' => $end];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function paginationBoundsTuple(int $current, int $total): array
    {
        $start = max(1, $current - 2);
        $end = min($total, $start + 4);
        $start = max(1, $end - 4);
        return [$start, $end];
    }

    /**
     * Active site language id, resolved the same way as `resolveSite()`:
     * Extbase wraps the request and may strip the `language` attribute, so
     * fall back to the global PSR-7 request. Returns `null` only when the
     * frontend has no language context at all (which shouldn't happen in a
     * normal page rendering).
     */
    private function resolveCurrentLanguageId(): ?int
    {
        $language = $this->request->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return $language->getLanguageId();
        }
        $globalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($globalRequest !== null) {
            $language = $globalRequest->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                return $language->getLanguageId();
            }
        }
        return null;
    }

    /**
     * Lowercase two-letter ISO code of the current site language, or
     * empty string when no SiteLanguage is in the request context.
     * Used to filter on the LanguageDetector-populated
     * `contentLanguage` field at search time — see the
     * restrictToCurrentLanguage block above.
     */
    private function resolveCurrentLanguageIsoCode(): string
    {
        $language = $this->request->getAttribute('language');
        if (!($language instanceof SiteLanguage)) {
            $globalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
            $language = $globalRequest?->getAttribute('language');
        }
        if (!($language instanceof SiteLanguage)) {
            return '';
        }
        // TYPO3 v14 removed SiteLanguage::getTwoLetterIsoCode() — the
        // replacement path is the Locale value object: ->getLocale()
        // returns a Symfony Intl Locale, ->getLanguageCode() the
        // ISO-639-1 stem ("de", "en", "fr").
        return strtolower((string)$language->getLocale()->getLanguageCode());
    }

    private function resolveLanguageLabel(Site $site, int $languageId): string
    {
        try {
            return $site->getLanguageById($languageId)->getTitle();
        } catch (\Throwable) {
            return (string)$languageId;
        }
    }

    /**
     * Sort presets used only when meilisearch.sortOptions is unset or wiped
     * by an integrator — keeps the result page functional rather than rendering
     * an empty <select>. The shipped settings.yaml provides the same list, so
     * this branch isn't reached in default installs.
     *
     * @return list<array{value:string,labelKey:string}>
     */
    private function fallbackSortOptions(): array
    {
        return [
            ['value' => '',                'labelKey' => 'search.sort.relevance'],
            ['value' => 'datetime:desc',   'labelKey' => 'search.sort.datetime.desc'],
            ['value' => 'datetime:asc',    'labelKey' => 'search.sort.datetime.asc'],
            ['value' => 'fileSize:desc',   'labelKey' => 'search.sort.fileSize.desc'],
            ['value' => 'fileSize:asc',    'labelKey' => 'search.sort.fileSize.asc'],
        ];
    }

    /**
     * @return array<string, array{attribute:string,label:string,widget:string,sort:string,maxItems:int,collapsed:bool,showCounts:bool,extra:array<string,mixed>}>
     */
    private function indexFacetConfigs(Site $site): array
    {
        $out = [];
        foreach ($this->configProvider->facets($site) as $facet) {
            $out[$facet->attribute] = [
                'attribute'  => $facet->attribute,
                'label'      => $facet->label,
                'widget'     => $facet->widget,
                'sort'       => $facet->sort,
                'maxItems'   => $facet->maxItems,
                'collapsed'  => $facet->collapsed,
                'showCounts' => $facet->showCounts,
                'extra'      => $facet->extra,
            ];
        }
        return $out;
    }
}

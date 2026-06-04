<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;
use WapplerSystems\Meilisearch\Service\SearchService;

final class SearchController extends ActionController
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly SiteFinder $siteFinder,
        private readonly SearchConfigurationProvider $configProvider,
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

    public function searchAction(string $q = ''): ResponseInterface
    {
        $this->view->assign('query', $q);
        return $this->htmlResponse();
    }

    /**
     * @param array<string,array<int,string>> $filters
     */
    public function resultsAction(string $q = '', int $page = 1, array $filters = [], int $hybrid = 0, string $sort = ''): ResponseInterface
    {
        if (strtoupper($this->request->getMethod()) === 'POST') {
            return $this->redirect('results', null, null, [
                'q' => $q,
                'page' => $page,
                'filters' => $filters,
                'hybrid' => $hybrid,
                'sort' => $sort,
            ]);
        }

        $site = $this->resolveSite();
        if (!$site instanceof Site) {
            return $this->htmlResponse();
        }
        // Per-plugin TypoScript wins when explicitly set (preserves operator
        // overrides on existing site packages); otherwise the new Site-Settings
        // defaults from meilisearch.frontend.perPage / meilisearch.facets apply.
        $perPage = isset($this->settings['perPage']) && (int)$this->settings['perPage'] > 0
            ? (int)$this->settings['perPage']
            : $this->configProvider->defaultPerPage($site);
        $tsFacets = trim((string)($this->settings['facets'] ?? ''));
        if ($tsFacets !== '') {
            $facetList = array_values(array_filter(array_map('trim', explode(',', $tsFacets))));
        } else {
            $facetList = $this->configProvider->facetAttributes($site);
        }

        // Integrator switch (per-site flag in settings.yaml /
        // settings.definitions.yaml): a single per-language search page
        // hides the language facet and force-filters results to the
        // active site language. Any incoming `filters[language]` from the
        // URL is discarded — the visitor must not be able to escape the
        // scope by hand-crafting the query string. Read directly from the
        // site settings so the fragment middleware (which has no Extbase
        // plugin context) can apply the same flag identically.
        $restrictToLanguage = (bool)$site->getSettings()->get('meilisearch.restrictToCurrentLanguage', false);
        if ($restrictToLanguage) {
            $currentLanguageId = $this->resolveCurrentLanguageId();
            if ($currentLanguageId !== null) {
                $filters['language'] = [$currentLanguageId];
            }
            $facetList = array_values(array_filter(
                $facetList,
                static fn (string $f): bool => $f !== 'language',
            ));
        }

        $hybridAvailable = trim((string)$site->getSettings()->get('meilisearch.embedder.source', '')) !== '';
        $useHybrid = $hybridAvailable && $hybrid === 1;

        // Sort: a single "field:direction" string from the FE, or empty
        // for relevance-only ranking. The SearchService accepts a list
        // — wrap the scalar; it handles "" / null gracefully.
        $sortOption = trim($sort);

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
            $hit['languageLabel'] = $this->resolveLanguageLabel($site, (int)($hit['language'] ?? 0));
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

        $this->view->assignMultiple([
            'query' => $q,
            'page' => max(1, $page),
            'result' => $result,
            'filters' => $filters,
            'hybrid' => $useHybrid ? 1 : 0,
            'hybridAvailable' => $hybridAvailable,
            'sort' => $sortOption,
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

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;
use WapplerSystems\Meilisearch\Service\AccessControlFilter;
use WapplerSystems\Meilisearch\Service\SearchResult;
use WapplerSystems\Meilisearch\Service\SearchService;

/**
 * Server-side render of the search-result region for the frontend Ajax
 * refresh path. Bypasses Extbase + page rendering — TYPO3's PAGEVIEW
 * always wraps a plugin output in the full HTML envelope, which is the
 * opposite of what we want when JS swaps innerHTML.
 *
 * Path:  /_ws_meilisearch/search-fragment?q=…&page=…&filters[type][]=page&hybrid=1&sort=…
 *
 * Mirrors SearchController::resultsAction in shape (same parameters,
 * same SearchService call, same view variables) but renders just the
 * Search/ResultRegion partial via StandaloneView.
 */
final class SearchFragmentEndpoint implements MiddlewareInterface
{
    private const PATH = '/_ws_meilisearch/search-fragment';
    private const TEMPLATE_PATH = 'EXT:ws_meilisearch/Resources/Private/Templates/Search/ResultsFragment.html';
    private const TEMPLATE_ROOT = 'EXT:ws_meilisearch/Resources/Private/Templates';
    private const PARTIAL_ROOT = 'EXT:ws_meilisearch/Resources/Private/Partials';
    private const LAYOUT_ROOT = 'EXT:ws_meilisearch/Resources/Private/Layouts';

    public function __construct(
        private readonly SearchService $searchService,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly SearchConfigurationProvider $configProvider,
        private readonly AccessControlFilter $accessControlFilter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Match the endpoint as a suffix so the JS can prepend the active
        // language base (e.g. /de/_ws_meilisearch/search-fragment). That
        // way the SiteResolver also assigns a `language` attribute to the
        // request, which we need for the restrictToCurrentLanguage filter.
        // Bare /_ws_meilisearch/search-fragment still works as a fallback
        // for tests / curl, just without language scoping.
        $path = $request->getUri()->getPath();
        if ($path !== self::PATH && !str_ends_with(rtrim($path, '/'), self::PATH)) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return new HtmlResponse('', 404);
        }

        $params = $request->getQueryParams();
        $q = trim((string)($params['q'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $rawFilters = $params['filters'] ?? [];
        $filters = $this->sanitiseFilters(is_array($rawFilters) ? $rawFilters : []);
        $sort = trim((string)($params['sort'] ?? ''));
        $hybridRequested = (int)($params['hybrid'] ?? 0) === 1;

        $hybridAvailable = trim((string)$site->getSettings()->get('meilisearch.embedder.source', '')) !== '';
        $useHybrid = $hybridAvailable && $hybridRequested;

        $perPage = $this->configProvider->defaultPerPage($site);
        $facetList = $this->configProvider->facetAttributes($site);

        // Same single-language-page UX switch the SearchController honors:
        // hide the language facet, force-filter to the active language so
        // visitors can't escape the scope via URL params.
        $restrictToLanguage = (bool)$site->getSettings()->get('meilisearch.restrictToCurrentLanguage', false);
        if ($restrictToLanguage) {
            $language = $request->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                $filters['language'] = [(string)$language->getLanguageId()];
            }
            $facetList = array_values(array_filter(
                $facetList,
                static fn (string $f): bool => $f !== 'language',
            ));
        }

        // FE-access-control: visitor only sees public docs + their
        // group-restricted docs. AND-conjoined with the user filters.
        $filters = $this->accessControlFilter->applyTo($filters, $site, $request);

        $result = $this->searchService->search($site, $q, [
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
            'facets' => $facetList,
            'hybrid' => $useHybrid,
            'sort' => $sort,
        ]);

        $hits = [];
        foreach ($result->hits as $hit) {
            // Hide the per-hit language hint when the result set is scoped to
            // one language (redundant); the partials guard on this being set.
            $hit['languageLabel'] = $restrictToLanguage
                ? ''
                : $this->resolveLanguageLabel($site, (int)($hit['language'] ?? 0));
            $hit['displayPartial'] = $this->configProvider->resolveDisplayPartial($site, (string)($hit['type'] ?? ''));
            $hits[] = $hit;
        }
        $result = new SearchResult(
            hits: $hits,
            totalHits: $result->totalHits,
            facets: $result->facets,
            page: $result->page,
            perPage: $result->perPage,
        );

        $languageLabels = [];
        foreach ($site->getAllLanguages() as $language) {
            $languageLabels[(string)$language->getLanguageId()] = $language->getTitle();
        }

        $view = $this->createView($request);
        $view->assignMultiple([
            'query' => $q,
            'page' => $page,
            'result' => $result,
            'filters' => $filters,
            'hybrid' => $useHybrid ? 1 : 0,
            'hybridAvailable' => $hybridAvailable,
            'sort' => $sort,
            'sortOptions' => $this->configProvider->sortOptions($site) ?: $this->fallbackSortOptions(),
            'facetConfigs' => $this->indexFacetConfigs($site),
            'languageLabels' => $languageLabels,
            'paginationWindow' => $this->paginationWindow($result->page, $result->getTotalPages()),
            'paginationFirst' => 0,
            'paginationLast' => 0,
        ] + $this->paginationBoundaries($result->page, $result->getTotalPages()));

        return new HtmlResponse($view->render());
    }

    /**
     * @param array<mixed> $raw
     * @return array<string,list<string>>
     */
    private function sanitiseFilters(array $raw): array
    {
        $clean = [];
        foreach ($raw as $attribute => $values) {
            if (!is_string($attribute) || !is_array($values)) {
                continue;
            }
            // attribute name is used as a Meilisearch facet identifier — only
            // allow safe chars to keep the query well-formed.
            if (preg_match('/^[a-zA-Z0-9_]+$/', $attribute) !== 1) {
                continue;
            }
            $list = [];
            foreach ($values as $v) {
                if (is_string($v) || is_int($v)) {
                    $list[] = (string)$v;
                }
            }
            if ($list !== []) {
                $clean[$attribute] = $list;
            }
        }
        return $clean;
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

    /**
     * @return list<int>
     */
    private function paginationWindow(int $current, int $total): array
    {
        if ($total <= 1) {
            return [];
        }
        $start = max(1, $current - 2);
        $end = min($total, $start + 4);
        $start = max(1, $end - 4);
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
        $start = max(1, $current - 2);
        $end = min($total, $start + 4);
        $start = max(1, $end - 4);
        return ['paginationFirst' => $start, 'paginationLast' => $end];
    }

    private function createView(ServerRequestInterface $request): ViewInterface
    {
        // The fragment template re-uses Search/ResultRegion, which calls
        // f:uri.action(…) on its pagination links. f:uri.action needs an
        // Extbase request attribute on the PSR-7 request to know which
        // plugin/controller/action to link to. The middleware runs outside
        // the Extbase request lifecycle, so we attach the parameters by
        // hand — same plugin metadata as ext_localconf::configurePlugin().
        $extbase = new ExtbaseRequestParameters();
        $extbase->setPluginName('Search');
        $extbase->setControllerExtensionName('WsMeilisearch');
        $extbase->setControllerName('Search');
        $extbase->setControllerActionName('results');
        $requestWithExtbase = $request->withAttribute('extbase', $extbase);

        return $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: [self::TEMPLATE_ROOT],
            partialRootPaths: [self::PARTIAL_ROOT],
            layoutRootPaths: [self::LAYOUT_ROOT],
            templatePathAndFilename: self::TEMPLATE_PATH,
            request: $requestWithExtbase,
        ));
    }
}

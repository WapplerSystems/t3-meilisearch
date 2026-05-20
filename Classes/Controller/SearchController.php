<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\Meilisearch\Service\SearchService;

final class SearchController extends ActionController
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly SiteFinder $siteFinder,
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
        $perPage = (int)($this->settings['perPage'] ?? 20);
        $facetList = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($this->settings['facets'] ?? ''))
        )));

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

        $this->view->assignMultiple([
            'query' => $q,
            'page' => max(1, $page),
            'result' => $result,
            'filters' => $filters,
            'hybrid' => $useHybrid ? 1 : 0,
            'hybridAvailable' => $hybridAvailable,
            'sort' => $sortOption,
            // Pre-built links so templates don't have to compute them.
            'sortOptions' => $this->sortOptions(),
        ]);
        return $this->htmlResponse();
    }

    /**
     * Sort presets exposed to the template. The default is empty
     * (relevance ranking) — we surface the most commonly useful sort
     * directions across the unified-index field set. Site packages can
     * override / extend by replacing the partial that consumes this.
     *
     * @return list<array{value:string,label:string}>
     */
    private function sortOptions(): array
    {
        return [
            ['value' => '',                'label' => 'Relevance'],
            ['value' => 'datetime:desc',   'label' => 'Newest first'],
            ['value' => 'datetime:asc',    'label' => 'Oldest first'],
            ['value' => 'fileSize:desc',   'label' => 'Largest file first'],
            ['value' => 'fileSize:asc',    'label' => 'Smallest file first'],
        ];
    }
}

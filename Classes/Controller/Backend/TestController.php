<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\SearchService;

/**
 * The "Test search & RAG" tab. Lets editors fire ad-hoc queries
 * against a site without leaving the BE — handy for sanity-checking
 * a freshly tuned documentTemplate or systemPrompt before pushing
 * settings to production.
 *
 * Split out of OverviewController so the controller's dependency
 * surface shrinks: search + RAG services aren't pulled in for tabs
 * that don't need them.
 */
final class TestController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly SearchService $searchService,
        private readonly RagService $ragService,
        private readonly BackendContext $context,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $query = (string)($request->getQueryParams()['q'] ?? '');
        $askQuery = (string)($request->getQueryParams()['ask'] ?? '');
        $siteId = (string)($request->getQueryParams()['site'] ?? '');
        $hybrid = (bool)($request->getQueryParams()['hybrid'] ?? false);
        $sort = trim((string)($request->getQueryParams()['sort'] ?? ''));
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $rawFilters = $request->getQueryParams()['filter'] ?? [];
        $filters = is_array($rawFilters) ? array_filter(array_map('strval', $rawFilters), static fn ($v) => $v !== '') : [];

        $sites = $this->siteFinder->getAllSites();
        $site = $this->resolveSite($siteId, $sites);
        if ($site !== null) {
            $siteId = $site->getIdentifier();
        }

        $searchResult = null;
        $hasSearchInput = $query !== '' || $sort !== '' || $filters !== [];
        if ($site instanceof Site && $hasSearchInput) {
            $searchResult = $this->searchService->search($site, $query, [
                'hybrid' => $hybrid,
                'page' => $page,
                'perPage' => 10,
                'facets' => ['type', 'language'],
                'filters' => $filters,
                'sort' => $sort,
            ]);
        }

        $ragAnswer = null;
        $ragSources = [];
        if ($site instanceof Site && $askQuery !== '') {
            $ragAnswer = $this->ragService->ask($site, $askQuery);
            // Pre-flag sources as cited so the template doesn't need a
            // nested loop just to render the ✓ marker.
            $citedSet = array_flip($ragAnswer->citedIds);
            foreach ($ragAnswer->sources as $src) {
                $id = (string)($src['id'] ?? '');
                $src['cited'] = isset($citedSet[$id]);
                $ragSources[] = $src;
            }
        }

        $moduleTemplate->assignMultiple([
            'siteOptions' => array_map(static fn (Site $s) => $s->getIdentifier(), array_values($sites)),
            'selectedSite' => $siteId,
            'query' => $query,
            'askQuery' => $askQuery,
            'hybrid' => $hybrid,
            'sort' => $sort,
            'page' => $page,
            'filters' => $filters,
            'searchResult' => $searchResult,
            'ragAnswer' => $ragAnswer,
            'ragSources' => $ragSources,
            'examples' => $this->buildExamples($site, $siteId),
            'prevPageUrl' => $searchResult?->getHasPreviousPage()
                ? $this->buildPageUrl($request, $page - 1)
                : null,
            'nextPageUrl' => $searchResult?->getHasNextPage()
                ? $this->buildPageUrl($request, $page + 1)
                : null,
            ...$this->context->tabNavData(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Test');
    }

    /**
     * Resolve the selected site, falling back to the first site that
     * is actually configured for Meilisearch — landing on a "not
     * configured" site by accident shows nothing and confuses
     * first-time users.
     *
     * @param array<string, Site> $sites
     */
    private function resolveSite(string $siteId, array $sites): ?Site
    {
        if ($siteId !== '') {
            try {
                return $this->siteFinder->getSiteByIdentifier($siteId);
            } catch (\Throwable) {
                // fall through to fallback
            }
        }
        if ($sites === []) {
            return null;
        }
        foreach ($sites as $candidate) {
            if (trim((string)$candidate->getSettings()->get('meilisearch.url', '')) !== '') {
                return $candidate;
            }
        }
        return $sites[array_key_first($sites)];
    }

    /**
     * Build a paginated link by replacing the `page` parameter in the
     * current request's query string. We can't use f:link.action here
     * because this module isn't Extbase — there's no Request in the
     * ViewHelper scope to resolve from.
     */
    private function buildPageUrl(ServerRequestInterface $request, int $page): string
    {
        $params = $request->getQueryParams();
        $params['page'] = $page;
        // Drop the BE module token; BackendUriBuilder regenerates it.
        unset($params['token'], $params['action']);
        return $this->context->route('test', $params);
    }

    /**
     * Preset queries that demonstrate each major feature, rendered as
     * clickable cards on the Test page. The presets stay available
     * even when the selected site doesn't support the specific
     * feature — clicking a hybrid example without an embedder
     * configured just shows the regular keyword result, which is
     * still useful as a "what does it look like" preview.
     *
     * @return list<array{label:string,description:string,feature:string,params:array<string,mixed>,url:string}>
     */
    private function buildExamples(?Site $site, string $siteId): array
    {
        $hasEmbedder = $site instanceof Site
            && trim((string)$site->getSettings()->get('meilisearch.embedder.source', '')) !== '';
        $hasRag = $site instanceof Site
            && trim((string)$site->getSettings()->get('meilisearch.rag.provider', '')) !== '';

        $base = ['site' => $siteId];
        $examples = [
            [
                'label' => 'Keyword search',
                'description' => 'Plain typo-tolerant full-text search across pages, news, and indexed files.',
                'feature' => 'phase 1',
                // Pick a word likely to hit on any docs site. Operator
                // can edit the query after the example loads — the
                // value here just seeds the input.
                'params' => $base + ['q' => 'guide'],
            ],
            [
                'label' => 'Filter: only files',
                'description' => 'Same query restricted to `type=file` — handy for "find me the PDF".',
                'feature' => 'phase 1 + facets',
                'params' => $base + ['q' => '', 'filter' => ['type' => 'file']],
            ],
            [
                'label' => 'Sort by file size',
                'description' => 'Empty query + sort descending. Surfaces the biggest indexed binaries.',
                'feature' => 'sort',
                'params' => $base + ['q' => '', 'sort' => 'fileSize:desc'],
            ],
            [
                'label' => 'Pagination',
                'description' => 'Empty query → all docs, walk pages with Prev / Next at the bottom.',
                'feature' => 'pagination',
                'params' => $base + ['q' => '', 'page' => 1],
            ],
            [
                'label' => 'Hybrid (semantic + keyword)',
                'description' => $hasEmbedder
                    ? 'Vector + keyword blend — finds docs even when the wording is paraphrased.'
                    : 'Needs an embedder configured. The toggle stays a no-op on sites without one.',
                'feature' => 'phase 3' . ($hasEmbedder ? '' : ' (no embedder)'),
                'params' => $base + ['q' => 'how do I reset my password', 'hybrid' => 1],
            ],
            [
                'label' => 'RAG: ask the site',
                'description' => $hasRag
                    ? 'LLM-grounded answer with cited sources, retrieval bias toward the question topic.'
                    : 'Needs a RAG provider configured. Click anyway to see the "disabled" status.',
                'feature' => 'phase 4' . ($hasRag ? '' : ' (no provider)'),
                'params' => $base + ['ask' => 'What is this site about?'],
            ],
        ];
        foreach ($examples as &$ex) {
            $ex['url'] = $this->context->route('test', $ex['params']);
        }
        return $examples;
    }
}

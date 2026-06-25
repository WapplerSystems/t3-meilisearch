<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Meilisearch\Contracts\TasksQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Service\EmbedderConfigurator;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * "Dashboard" tab: a live operational view of each site's Meilisearch
 * index, pulled straight from the engine (no cache) so an operator can
 * see at a glance what the index is doing right now:
 *
 *   - document count + isIndexing flag,
 *   - documents per record type (facet distribution on `type`),
 *   - task queue: enqueued / processing / failed totals + the most recent
 *     failures with their error message (the fast path to spotting a
 *     wedged embedder or a rejected settings update),
 *   - a settings snapshot (searchableAttributes, rankingRules, embedders).
 *
 * Admin-only backend module, so the raw Meilisearch client (master key
 * from site settings) is used server-side directly — nothing is exposed
 * to the frontend.
 */
final class DashboardController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly SearchEngineFactory $engineFactory,
        private readonly BackendContext $context,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $cards = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $cards[] = $this->buildCard($site);
        }

        $moduleTemplate->assignMultiple([
            'cards' => $cards,
            ...$this->context->tabNavData(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Dashboard/Index');
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCard(Site $site): array
    {
        $card = [
            'siteIdentifier' => $site->getIdentifier(),
            'siteTitle' => (string)($site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier()),
            'configured' => trim((string)$site->getSettings()->get('meilisearch.url', '')) !== '',
            'indexName' => '',
            'docCount' => null,
            'isIndexing' => false,
            'typeDistribution' => [],
            'tasks' => ['enqueued' => null, 'processing' => null, 'failed' => null],
            'recentFailed' => [],
            'searchableAttributes' => [],
            'rankingRules' => [],
            'embedders' => [],
            'error' => null,
        ];
        if (!$card['configured']) {
            return $card;
        }

        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            $card['error'] = 'Meilisearch client could not be created (check meilisearch.url / key).';
            return $card;
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $card['indexName'] = $indexName;
        $index = $client->index($indexName);

        try {
            $stats = $index->stats();
            $card['docCount'] = (int)($stats['numberOfDocuments'] ?? 0);
            $card['isIndexing'] = (bool)($stats['isIndexing'] ?? false);
        } catch (\Throwable $e) {
            // No index / unreachable engine — report and stop; the rest
            // would just repeat the same failure.
            $card['error'] = $e->getMessage();
            return $card;
        }

        // Documents per record type — a 0-hit faceted search is the cheapest
        // way to get the per-type distribution the FE facet also uses.
        try {
            $result = $index->search('', ['facets' => ['type'], 'limit' => 0]);
            $dist = $result->getFacetDistribution()['type'] ?? [];
            if (is_array($dist)) {
                arsort($dist);
                $card['typeDistribution'] = $dist;
            }
        } catch (\Throwable) {
            // Facet may be unavailable if `type` isn't filterable — non-fatal.
        }

        // Task queue: exact totals per status + the most recent failures.
        try {
            $card['tasks']['enqueued'] = $this->countTasks($client, $indexName, 'enqueued');
            $card['tasks']['processing'] = $this->countTasks($client, $indexName, 'processing');
            $failed = $client->getTasks(
                (new TasksQuery())->setIndexUids([$indexName])->setStatuses(['failed'])->setLimit(5),
            );
            $card['tasks']['failed'] = $failed->getTotal();
            foreach ($failed->getResults() as $task) {
                $card['recentFailed'][] = [
                    'uid' => $task['uid'] ?? null,
                    'type' => (string)($task['type'] ?? ''),
                    'error' => (string)($task['error']['message'] ?? ($task['error']['code'] ?? '')),
                    'finishedAt' => (string)($task['finishedAt'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // Task endpoint hiccup — leave the nulls, the rest still renders.
        }

        try {
            $card['searchableAttributes'] = $index->getSearchableAttributes();
        } catch (\Throwable) {
        }
        try {
            $card['rankingRules'] = $index->getRankingRules();
        } catch (\Throwable) {
        }
        try {
            $embedders = $index->getEmbedders();
            if (is_array($embedders)) {
                $card['embedders'] = array_keys($embedders);
            }
        } catch (\Throwable) {
            // getEmbedders 400s when no embedder is configured — fine.
        }

        return $card;
    }

    private function countTasks(\Meilisearch\Client $client, string $indexName, string $status): int
    {
        return $client->getTasks(
            (new TasksQuery())->setIndexUids([$indexName])->setStatuses([$status])->setLimit(1),
        )->getTotal();
    }
}

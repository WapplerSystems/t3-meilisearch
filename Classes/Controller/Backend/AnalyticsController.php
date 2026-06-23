<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;

/**
 * Backend "Analytics" tab — aggregates the rows
 * SearchAnalyticsLogger writes into tx_wsmeilisearch_search_log so
 * editors can see top queries, zero-result queries, and hybrid /
 * suggest / search volume over rolling windows.
 *
 * The numbers come straight from the log table — no Meilisearch
 * round-trip — so the dashboard is fast (single-digit ms per panel)
 * and works even when the engine is down. When analytics is disabled
 * on every site the dashboard renders an empty-state with the
 * setting name to flip.
 *
 * No write actions: this tab is read-only. Cleanup happens via the
 * scheduler task (or manual `DELETE` if needed), not through the BE
 * surface — accidental deletion of analytics history is annoying
 * and a confirm modal isn't worth the extra plumbing.
 */
final class AnalyticsController
{
    private const TABLE = 'tx_wsmeilisearch_search_log';
    private const PANELS_LIMIT = 20;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly BackendContext $context,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $params = $request->getQueryParams();

        // Window selector: 1 / 7 / 30 days. Default 7 — long enough to
        // smooth out daily seasonality, short enough that yesterday's
        // PR-launch spike is still visible.
        $windowDays = in_array((int)($params['days'] ?? 0), [1, 7, 30, 90], true)
            ? (int)$params['days']
            : 7;
        $cutoff = time() - ($windowDays * 86400);

        // Site selector — defaults to all sites combined. Single-site
        // mode shows the site identifier in the panel headers so it's
        // clear when filtering is active.
        $siteFilter = (string)($params['site'] ?? '');
        $availableSites = [];
        foreach ($this->siteFinder->getAllSites() as $s) {
            $availableSites[] = $s->getIdentifier();
        }
        if (!in_array($siteFilter, $availableSites, true)) {
            $siteFilter = '';
        }

        $analyticsEnabled = $this->analyticsEnabledAnywhere();

        // Keyword-search panels exclude RAG rows so RAG questions don't
        // skew the search top-queries / zero-result histograms. RAG gets
        // its own panel below; the source breakdown stays unfiltered.
        $keywordSources = ['search', 'suggest'];
        $totals = $this->totals($cutoff, $siteFilter, $keywordSources);
        $topQueries = $this->topQueries($cutoff, $siteFilter, false, $keywordSources);
        $zeroResultQueries = $this->topQueries($cutoff, $siteFilter, true, $keywordSources);
        $sourceBreakdown = $this->sourceBreakdown($cutoff, $siteFilter);
        $hybridRate = $this->hybridRate($cutoff, $siteFilter);

        $rag = $this->ragStats($cutoff, $siteFilter);
        $ragTopQueries = $this->topQueries($cutoff, $siteFilter, false, ['rag']);

        $moduleTemplate->assignMultiple([
            'analyticsEnabled' => $analyticsEnabled,
            'windowDays' => $windowDays,
            'siteFilter' => $siteFilter,
            'availableSites' => $availableSites,
            'totals' => $totals,
            'topQueries' => $topQueries,
            'zeroResultQueries' => $zeroResultQueries,
            'sourceBreakdown' => $sourceBreakdown,
            'hybridRate' => $hybridRate,
            'rag' => $rag,
            'ragTopQueries' => $ragTopQueries,
            'baseUrl' => $this->context->route('analytics'),
            ...$this->context->tabNavData(),
            'active' => 'analytics',
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Analytics');
    }

    /**
     * @param list<string>|null $sources
     * @return array{rows:int, distinctQueries:int, zeroResultRows:int}
     */
    private function totals(int $cutoff, string $siteFilter, ?array $sources = null): array
    {
        $qb = $this->base($cutoff, $siteFilter, $sources);
        $rows = (int)$qb->count('uid')->executeQuery()->fetchOne();

        $qb = $this->base($cutoff, $siteFilter, $sources);
        $distinct = (int)$qb->addSelectLiteral('COUNT(DISTINCT query) AS distinctQueries')
            ->executeQuery()->fetchOne();

        $qb = $this->base($cutoff, $siteFilter, $sources);
        $zero = (int)$qb->andWhere($qb->expr()->eq('result_count', $qb->createNamedParameter(0, ParameterType::INTEGER)))
            ->count('uid')->executeQuery()->fetchOne();
        return ['rows' => $rows, 'distinctQueries' => $distinct, 'zeroResultRows' => $zero];
    }

    /**
     * @param list<string>|null $sources
     * @return list<array{query:string, hits:int, avgResults:float}>
     */
    private function topQueries(int $cutoff, string $siteFilter, bool $onlyZero, ?array $sources = null): array
    {
        $qb = $this->base($cutoff, $siteFilter, $sources);
        $qb->addSelectLiteral('query', 'COUNT(*) AS hits', 'AVG(result_count) AS avgResults');
        if ($onlyZero) {
            $qb->andWhere($qb->expr()->eq('result_count', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        }
        $qb->groupBy('query')
            ->orderBy('hits', 'DESC')
            ->setMaxResults(self::PANELS_LIMIT);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(static fn (array $r): array => [
            'query' => (string)$r['query'],
            'hits' => (int)$r['hits'],
            'avgResults' => round((float)$r['avgResults'], 1),
        ], $rows);
    }

    /**
     * @return list<array{source:string, count:int}>
     */
    private function sourceBreakdown(int $cutoff, string $siteFilter): array
    {
        $qb = $this->base($cutoff, $siteFilter);
        $qb->addSelectLiteral('source', 'COUNT(*) AS count')
            ->groupBy('source')
            ->orderBy('count', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(static fn (array $r): array => [
            'source' => (string)$r['source'],
            'count' => (int)$r['count'],
        ], $rows);
    }

    /**
     * @return array{hybrid:int, keyword:int, hybridPct:float}
     */
    private function hybridRate(int $cutoff, string $siteFilter): array
    {
        $qb = $this->base($cutoff, $siteFilter);
        $qb->addSelectLiteral('hybrid', 'COUNT(*) AS count')
            ->groupBy('hybrid');
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $hybrid = 0;
        $keyword = 0;
        foreach ($rows as $r) {
            if ((int)$r['hybrid'] === 1) {
                $hybrid = (int)$r['count'];
            } else {
                $keyword = (int)$r['count'];
            }
        }
        $total = $hybrid + $keyword;
        return [
            'hybrid' => $hybrid,
            'keyword' => $keyword,
            'hybridPct' => $total > 0 ? round($hybrid / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * RAG usage panel: volume + status mix + low-confidence rate over
     * the window. "Low confidence" = answered (status=ok) but the LLM
     * cited nothing — the answers worth a human spot-check.
     *
     * @return array{total:int, ok:int, noContext:int, failed:int, lowConfidence:int, avgCited:float}
     */
    private function ragStats(int $cutoff, string $siteFilter): array
    {
        $qb = $this->base($cutoff, $siteFilter, ['rag']);
        $qb->addSelectLiteral('status', 'COUNT(*) AS count')->groupBy('status');
        $byStatus = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $r) {
            $byStatus[(string)$r['status']] = (int)$r['count'];
        }
        $total = array_sum($byStatus);

        $qb = $this->base($cutoff, $siteFilter, ['rag']);
        $lowConfidence = (int)$qb
            ->andWhere(
                $qb->expr()->eq('status', $qb->createNamedParameter('ok')),
                $qb->expr()->eq('cited_count', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->count('uid')->executeQuery()->fetchOne();

        $qb = $this->base($cutoff, $siteFilter, ['rag']);
        $avgCited = (float)$qb->addSelectLiteral('AVG(cited_count) AS avgCited')
            ->executeQuery()->fetchOne();

        return [
            'total' => $total,
            'ok' => $byStatus['ok'] ?? 0,
            'noContext' => $byStatus['no_context'] ?? 0,
            'failed' => $byStatus['failed'] ?? 0,
            'lowConfidence' => $lowConfidence,
            'avgCited' => round($avgCited, 1),
        ];
    }

    /**
     * Site-setting probe — true when at least one configured site has
     * analytics.enabled. Drives the empty-state hint in the template
     * (no rows can mean "no traffic" OR "feature off"; this disambiguates).
     */
    private function analyticsEnabledAnywhere(): bool
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ((bool)$site->getSettings()->get('meilisearch.analytics.enabled', false) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string>|null $sources Restrict to these source values
     *        (e.g. ['search','suggest'] for the keyword panels, ['rag']
     *        for the RAG panel). Null = all sources (used by the source
     *        breakdown).
     */
    private function base(int $cutoff, string $siteFilter, ?array $sources = null): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->from(self::TABLE)
            ->where($qb->expr()->gte('crdate', $qb->createNamedParameter($cutoff, ParameterType::INTEGER)));
        if ($siteFilter !== '') {
            $qb->andWhere($qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteFilter)));
        }
        if ($sources !== null && $sources !== []) {
            $qb->andWhere($qb->expr()->in(
                'source',
                $qb->createNamedParameter($sources, \Doctrine\DBAL\ArrayParameterType::STRING),
            ));
        }
        return $qb;
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use CmsIg\Seal\Search\Condition\EqualCondition;
use CmsIg\Seal\Search\Condition\InCondition;
use CmsIg\Seal\Search\Condition\SearchCondition;
use CmsIg\Seal\Search\Facet\CountFacet;
use Meilisearch\Contracts\HybridSearchOptions;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Event\AfterSearchEvent;
use WapplerSystems\Meilisearch\Event\BeforeSearchEvent;

/**
 * Frontend-facing search wrapper.
 *
 * Two execution paths:
 *  - keyword: SEAL search builder → adapter-agnostic, portable to other engines
 *  - hybrid:  raw Meilisearch SDK so we can pass `hybrid.semanticRatio`, which
 *             SEAL 0.12 does not yet expose. Activated when the caller passes
 *             `hybrid: true` *and* the site has an embedder configured.
 */
final class SearchService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @param array{
     *     filters?: array<string,scalar|array<int,scalar>>,
     *     facets?: list<string>,
     *     page?: int,
     *     perPage?: int,
     *     hybrid?: bool,
     *     semanticRatio?: float,
     * } $options
     */
    public function search(Site $site, string $query, array $options = []): SearchResult
    {
        $before = new BeforeSearchEvent($query, $options);
        $this->eventDispatcher->dispatch($before);

        $engine = $this->engineFactory->createForSite($site);
        if ($engine === null) {
            $empty = SearchResult::empty();
            $this->eventDispatcher->dispatch(new AfterSearchEvent($before->query, $before->options, $empty));
            return $empty;
        }

        $page = max(1, (int)($before->options['page'] ?? 1));
        $perPage = max(1, (int)($before->options['perPage'] ?? 20));
        $useHybrid = (bool)($before->options['hybrid'] ?? false)
            && trim((string)$site->getSettings()->get('meilisearch.embedder.source', '')) !== '';

        try {
            $result = $useHybrid
                ? $this->hybridSearch($site, $before->query, $before->options, $page, $perPage)
                : $this->keywordSearch($site, $before->query, $before->options, $page, $perPage);
        } catch (\Throwable $e) {
            $this->logger?->error('Meilisearch search failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $result = SearchResult::empty();
        }

        $after = new AfterSearchEvent($before->query, $before->options, $result);
        $this->eventDispatcher->dispatch($after);
        return $after->result;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function keywordSearch(Site $site, string $query, array $options, int $page, int $perPage): SearchResult
    {
        $engine = $this->engineFactory->createForSite($site);
        $indexName = $this->engineFactory->getIndexName($site);

        $builder = $engine->createSearchBuilder($indexName)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage);

        if ($query !== '') {
            $builder->addFilter(new SearchCondition($query));
        }

        foreach ((array)($options['filters'] ?? []) as $field => $value) {
            if (is_array($value)) {
                $value = array_values(array_filter($value, static fn ($v) => $v !== '' && $v !== null));
                if ($value === []) {
                    continue;
                }
                $builder->addFilter(new InCondition((string)$field, $value));
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $builder->addFilter(new EqualCondition((string)$field, $value));
        }

        foreach ((array)($options['facets'] ?? []) as $facetField) {
            $field = (string)$facetField;
            if ($field === '') {
                continue;
            }
            $builder->addFacet(new CountFacet($field));
        }

        $sealResult = $builder->getResult();
        $hits = iterator_to_array($sealResult, false);
        return new SearchResult(
            hits: $hits,
            totalHits: $sealResult->total(),
            facets: $this->mapSealFacets($sealResult->facets()),
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @param array<string,mixed> $options
     */
    private function hybridSearch(Site $site, string $query, array $options, int $page, int $perPage): SearchResult
    {
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return SearchResult::empty();
        }
        $index = $client->index($this->engineFactory->getIndexName($site));

        $params = [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];

        $filter = $this->buildMeilisearchFilter((array)($options['filters'] ?? []));
        if ($filter !== '') {
            $params['filter'] = $filter;
        }

        $facets = [];
        foreach ((array)($options['facets'] ?? []) as $facetField) {
            $field = (string)$facetField;
            if ($field !== '') {
                $facets[] = $field;
            }
        }
        if ($facets !== []) {
            $params['facets'] = $facets;
        }

        $ratio = $this->resolveSemanticRatio($site, $options);
        $params['hybrid'] = (new HybridSearchOptions())
            ->setEmbedder(EmbedderConfigurator::EMBEDDER_NAME)
            ->setSemanticRatio($ratio)
            ->toArray();

        $response = $index->search($query, $params);
        $raw = $response->toArray();

        $hits = is_array($raw['hits'] ?? null) ? $raw['hits'] : [];
        $total = (int)($raw['estimatedTotalHits'] ?? $raw['totalHits'] ?? count($hits));
        $facetDistribution = is_array($raw['facetDistribution'] ?? null) ? $raw['facetDistribution'] : [];

        return new SearchResult(
            hits: $hits,
            totalHits: $total,
            facets: $this->mapMeilisearchFacets($facetDistribution),
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolveSemanticRatio(Site $site, array $options): float
    {
        if (array_key_exists('semanticRatio', $options) && is_numeric($options['semanticRatio'])) {
            return $this->clampRatio((float)$options['semanticRatio']);
        }
        return $this->clampRatio((float)$site->getSettings()->get('meilisearch.embedder.semanticRatio', 0.5));
    }

    private function clampRatio(float $ratio): float
    {
        return max(0.0, min(1.0, $ratio));
    }

    /**
     * Translate the same options.filters shape used by SEAL into a Meilisearch
     * filter expression. Strings are wrapped in double quotes; embedded quotes
     * are escaped. Numeric / boolean values are emitted unquoted so they hit
     * Meilisearch's numeric/boolean comparison.
     *
     * @param array<string,scalar|array<int,scalar>> $filters
     */
    private function buildMeilisearchFilter(array $filters): string
    {
        $parts = [];
        foreach ($filters as $field => $value) {
            $field = (string)$field;
            if ($field === '') {
                continue;
            }
            if (is_array($value)) {
                $value = array_values(array_filter($value, static fn ($v) => $v !== '' && $v !== null));
                if ($value === []) {
                    continue;
                }
                $encoded = array_map(fn ($v) => $this->encodeFilterValue($v), $value);
                $parts[] = $field . ' IN [' . implode(', ', $encoded) . ']';
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $parts[] = $field . ' = ' . $this->encodeFilterValue($value);
        }
        return implode(' AND ', $parts);
    }

    private function encodeFilterValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $string = (string)$value;
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
    }

    /**
     * SEAL returns CountFacet results nested under "count":
     *   ['type' => ['count' => ['page' => 1, 'news' => 4]]]
     *
     * @param array<string,mixed> $facets
     * @return array<string,array<string,int>>
     */
    private function mapSealFacets(array $facets): array
    {
        $out = [];
        foreach ($facets as $field => $payload) {
            if (!is_array($payload) || !isset($payload['count']) || !is_array($payload['count'])) {
                continue;
            }
            $flattened = [];
            foreach ($payload['count'] as $value => $count) {
                $flattened[(string)$value] = (int)$count;
            }
            if ($flattened !== []) {
                $out[(string)$field] = $flattened;
            }
        }
        return $out;
    }

    /**
     * Meilisearch returns facetDistribution as flat {attribute => {value => count}}.
     *
     * @param array<string,mixed> $distribution
     * @return array<string,array<string,int>>
     */
    private function mapMeilisearchFacets(array $distribution): array
    {
        $out = [];
        foreach ($distribution as $field => $values) {
            if (!is_array($values)) {
                continue;
            }
            $flattened = [];
            foreach ($values as $value => $count) {
                $flattened[(string)$value] = (int)$count;
            }
            if ($flattened !== []) {
                $out[(string)$field] = $flattened;
            }
        }
        return $out;
    }
}
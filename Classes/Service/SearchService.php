<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use CmsIg\Seal\Search\Condition\EqualCondition;
use CmsIg\Seal\Search\Condition\InCondition;
use CmsIg\Seal\Search\Condition\SearchCondition;
use CmsIg\Seal\Search\Facet\CountFacet;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Event\AfterSearchEvent;
use WapplerSystems\Meilisearch\Event\BeforeSearchEvent;

/**
 * Frontend-facing search wrapper.
 *
 * Builds a SEAL search request from the controller's options (query, filters,
 * facets, paging) and maps SEAL's Result into the template-friendly
 * SearchResult DTO so Fluid stays decoupled from the engine.
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

        $indexName = $this->engineFactory->getIndexName($site);
        $page = max(1, (int)($before->options['page'] ?? 1));
        $perPage = max(1, (int)($before->options['perPage'] ?? 20));

        $builder = $engine->createSearchBuilder($indexName)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage);

        if ($before->query !== '') {
            $builder->addFilter(new SearchCondition($before->query));
        }

        foreach ((array)($before->options['filters'] ?? []) as $field => $value) {
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

        foreach ((array)($before->options['facets'] ?? []) as $facetField) {
            $field = (string)$facetField;
            if ($field === '') {
                continue;
            }
            $builder->addFacet(new CountFacet($field));
        }

        try {
            $sealResult = $builder->getResult();
            $hits = iterator_to_array($sealResult, false);
            $result = new SearchResult(
                hits: $hits,
                totalHits: $sealResult->total(),
                facets: $this->mapFacets($sealResult->facets()),
                page: $page,
                perPage: $perPage,
            );
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
     * Flatten SEAL's facet structure into a {attribute => {value => count}} map
     * for Fluid.
     *
     * SEAL returns CountFacet results nested under a "count" sub-key, e.g.:
     *   ['type' => ['count' => ['page' => 1, 'news' => 4]]]
     * MinMax facets ship "min"/"max" instead — we ignore those for Phase 1.
     *
     * @param array<string,mixed> $facets
     * @return array<string,array<string,int>>
     */
    private function mapFacets(array $facets): array
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
}
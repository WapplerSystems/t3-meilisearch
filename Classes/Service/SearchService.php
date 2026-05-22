<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

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
 * Both keyword and hybrid paths use the raw Meilisearch SDK directly. SEAL's
 * highlight() returns through a `hitsToDocuments` generator that asserts every
 * requested highlight field is present in `_formatted` on every hit — but
 * Meilisearch only emits `_formatted` for fields the document actually has, so
 * the assertion fires on heterogeneous indexes where e.g. `subtitle` is only
 * set on pages. Going via the raw SDK sidesteps that and lets us use
 * attributesToCrop for snippet previews on both paths.
 */
final class SearchService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Fields the search engine should mark up with <mark>…</mark> around
     * matches, exposed on `hit._formatted.<field>` for the template
     * layer. Keep these in sync with the searchable fields declared by
     * the schema providers — adding a field here that isn't actually
     * searchable just bloats the response.
     */
    private const HIGHLIGHT_FIELDS = ['title', 'subtitle', 'description', 'abstract', 'keywords', 'teaser', 'bodytext'];

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
     *     sort?: list<string>|string,
     * } $options
     */
    public function search(Site $site, string $query, array $options = []): SearchResult
    {
        $before = new BeforeSearchEvent($query, $options);
        $this->eventDispatcher->dispatch($before);

        if ($this->engineFactory->createClientForSite($site) === null) {
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
        return $this->directSearch($site, $query, $options, $page, $perPage, null);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function hybridSearch(Site $site, string $query, array $options, int $page, int $perPage): SearchResult
    {
        $ratio = $this->resolveSemanticRatio($site, $options);
        $hybridParams = (new HybridSearchOptions())
            ->setEmbedder(EmbedderConfigurator::EMBEDDER_NAME)
            ->setSemanticRatio($ratio)
            ->toArray();
        return $this->directSearch($site, $query, $options, $page, $perPage, $hybridParams);
    }

    /**
     * Single execution path against the raw Meilisearch SDK. Used by both
     * keyword and hybrid search. Pass `$hybridParams` from `HybridSearchOptions`
     * to enable the semantic blend; pass `null` for plain keyword search.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed>|null $hybridParams
     */
    private function directSearch(Site $site, string $query, array $options, int $page, int $perPage, ?array $hybridParams): SearchResult
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

        if ($hybridParams !== null) {
            $params['hybrid'] = $hybridParams;
        }

        $sort = [];
        foreach ($this->normalizeSort($options['sort'] ?? null) as [$field, $direction]) {
            $sort[] = $field . ':' . $direction;
        }
        if ($sort !== []) {
            $params['sort'] = $sort;
        }

        $params['attributesToHighlight'] = self::HIGHLIGHT_FIELDS;
        $params['highlightPreTag'] = '<mark>';
        $params['highlightPostTag'] = '</mark>';
        $params['attributesToCrop'] = ['bodytext:200', 'description:200', 'teaser:200'];
        $params['cropMarker'] = '…';

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
     * Normalize the `sort` option into [field, direction] pairs.
     * Accepts:
     *  - a string `"field:desc"` (single sort)
     *  - a list of strings `["fileSize:desc", "title:asc"]` (multi-sort)
     *  - a list of [field, direction] tuples
     * Unknown directions default to asc — keyword that always works in both
     * SEAL and Meilisearch syntax. Empty / malformed entries are skipped so
     * a stray "" from a form submission doesn't poison the whole sort.
     *
     * @return iterable<array{0:string,1:'asc'|'desc'}>
     */
    private function normalizeSort(mixed $raw): iterable
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return;
        }
        $entries = is_array($raw) ? $raw : [$raw];
        foreach ($entries as $entry) {
            if (is_string($entry) && $entry !== '') {
                $parts = explode(':', $entry, 2);
                $field = trim($parts[0] ?? '');
                $dir = strtolower(trim($parts[1] ?? 'asc'));
            } elseif (is_array($entry) && count($entry) >= 2) {
                $field = trim((string)$entry[0]);
                $dir = strtolower(trim((string)$entry[1]));
            } else {
                continue;
            }
            if ($field === '') {
                continue;
            }
            yield [$field, $dir === 'desc' ? 'desc' : 'asc'];
        }
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
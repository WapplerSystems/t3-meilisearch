<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Meilisearch\Contracts\HybridSearchOptions;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;
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
 *
 * Highlight/crop/marker/tag values come from meilisearch.frontend.* and
 * meilisearch.display.*.{highlight,crop} via SearchConfigurationProvider;
 * see settings.definitions.yaml and settings.yaml in the Set.
 */
final class SearchService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Hardcoded fallback for sites whose display config doesn't declare any
     * highlight attributes — preserves the original SearchService behaviour
     * if an integrator wipes meilisearch.display.* without replacing it.
     */
    private const FALLBACK_HIGHLIGHT_FIELDS = ['title', 'subtitle', 'description', 'abstract', 'keywords', 'teaser', 'bodytext'];
    private const FALLBACK_CROP_FIELDS = ['bodytext:200', 'description:200', 'teaser:200'];

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SearchConfigurationProvider $configProvider,
        private readonly QueryRecoveryService $queryRecovery,
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
        $before = new BeforeSearchEvent($query, $options, $site);
        $this->eventDispatcher->dispatch($before);

        if ($this->engineFactory->createClientForSite($site) === null) {
            $empty = SearchResult::empty();
            $this->eventDispatcher->dispatch(new AfterSearchEvent($before->query, $before->options, $empty, $site));
            return $empty;
        }

        $page = max(1, (int)($before->options['page'] ?? 1));
        $perPage = max(1, (int)($before->options['perPage'] ?? 20));
        $useHybrid = (bool)($before->options['hybrid'] ?? false)
            && trim((string)$site->getSettings()->get('meilisearch.embedder.source', '')) !== '';

        // Resolve the strategy here instead of inside directSearch so both
        // the recovery pass and the analytics row see the value that was
        // actually in force.
        $before->options['matchingStrategy'] = $this->resolveMatchingStrategy($site, $before->options);

        try {
            $result = $useHybrid
                ? $this->hybridSearch($site, $before->query, $before->options, $page, $perPage)
                : $this->keywordSearch($site, $before->query, $before->options, $page, $perPage);
            $result = $this->recoverIfEmpty($site, $before->query, $before->options, $page, $perPage, $useHybrid, $result);
        } catch (\Throwable $e) {
            $this->logger?->error('Meilisearch search failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $result = SearchResult::empty();
        }

        // Hand the recovery outcome to the analytics listener: a row saying
        // "0 hits" reads very differently once it also says whether an
        // alternative was found, offered or applied.
        $before->options['__recovery'] = $result->recovery !== ''
            ? $result->recovery
            : ($result->alternatives !== [] ? 'offered' : ($result->totalHits === 0 ? 'none' : ''));
        $before->options['__alternatives'] = array_map(
            static fn (QueryAlternative $a): string => $a->kind . ':' . $a->query,
            $result->alternatives,
        );

        $after = new AfterSearchEvent($before->query, $before->options, $result, $site);
        $this->eventDispatcher->dispatch($after);
        return $after->result;
    }

    /**
     * Zero-result rescue. Asks QueryRecoveryService for near spellings that
     * do have hits, then decides how much to do with them:
     *
     *   • a single high-confidence alternative (typo fixed, compound split,
     *     tokens joined) is run for the visitor right away — landing on an
     *     empty page when one character was wrong is the worst outcome of
     *     the three;
     *   • anything else is handed to the template as "did you mean" chips,
     *     so relaxing the query stays the visitor's decision.
     *
     * Opt out per call with `['recover' => false]` — RAG retrieval does,
     * because it has its own fallback ladder and must not pay for a second.
     *
     * @param array<string,mixed> $options
     */
    private function recoverIfEmpty(
        Site $site,
        string $query,
        array $options,
        int $page,
        int $perPage,
        bool $useHybrid,
        SearchResult $result,
    ): SearchResult {
        if ($result->totalHits > 0 || trim($query) === '') {
            return $result;
        }
        if (($options['recover'] ?? true) === false) {
            return $result;
        }
        if (!(bool)$site->getSettings()->get('meilisearch.search.recovery.enabled', true)) {
            return $result;
        }

        $filter = $this->withKnowledgeResourceExclusion(
            $this->buildMeilisearchFilter((array)($options['filters'] ?? [])),
            $options,
        );
        $alternatives = $this->queryRecovery->recover(
            $site,
            $query,
            $filter,
            $useHybrid ? $this->hybridParams($site, $options) : null,
            $this->highlightFields($site),
            max(1, (int)$site->getSettings()->get('meilisearch.search.recovery.maxAlternatives', 4)),
        );
        if ($alternatives === []) {
            return $result;
        }

        $best = $alternatives[0];
        $autoApply = (bool)$site->getSettings()->get('meilisearch.search.recovery.autoApply', true);
        if (!$autoApply || !$best->getIsAutoApplicable()) {
            return $result->withRecovery($alternatives, originalQuery: $query);
        }

        // Re-run the real search with the better spelling. Deliberately
        // bypasses search() so no second recovery pass and no duplicate
        // Before/AfterSearchEvent can fire.
        $recoveredOptions = $options;
        $recoveredOptions['page'] = 1;
        try {
            $recovered = $useHybrid
                ? $this->hybridSearch($site, $best->query, $recoveredOptions, 1, $perPage)
                : $this->keywordSearch($site, $best->query, $recoveredOptions, 1, $perPage);
        } catch (\Throwable $e) {
            $this->logger?->warning('Recovered query "{query}" failed: {message}', [
                'query' => $best->query,
                'message' => $e->getMessage(),
            ]);
            return $result->withRecovery($alternatives, originalQuery: $query);
        }
        if ($recovered->totalHits < 1) {
            return $result->withRecovery($alternatives, originalQuery: $query);
        }

        return $recovered->withRecovery(
            alternatives: array_values(array_slice($alternatives, 1)),
            effectiveQuery: $best->query,
            originalQuery: $query,
            recovery: $best->kind,
        );
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolveMatchingStrategy(Site $site, array $options): string
    {
        $strategy = trim((string)($options['matchingStrategy'] ?? ''));
        if ($strategy !== '') {
            return $strategy;
        }
        return trim((string)$site->getSettings()->get('meilisearch.search.matchingStrategy', 'all'));
    }

    /**
     * Highlightable text fields — also the fields QueryRecoveryService
     * reads the correctly spelled word out of.
     *
     * @return list<string>
     */
    private function highlightFields(Site $site): array
    {
        $fields = $this->configProvider->highlightAttributes($site) ?: self::FALLBACK_HIGHLIGHT_FIELDS;
        if (!in_array('content', $fields, true)) {
            $fields[] = 'content';
        }
        return array_values($fields);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function hybridParams(Site $site, array $options): array
    {
        return (new HybridSearchOptions())
            ->setEmbedder(EmbedderConfigurator::EMBEDDER_NAME)
            ->setSemanticRatio($this->resolveSemanticRatio($site, $options))
            ->toArray();
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
        return $this->directSearch($site, $query, $options, $page, $perPage, $this->hybridParams($site, $options));
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

        // Knowledge resources are indexed for RAG-grounding but must not show
        // up in the FE result list. Excluded by default; callers that need
        // them (RAG retrieval) opt in via $options['includeKnowledgeResources'].
        // Applied to the main query AND every disjunctive-facet side query
        // below — otherwise removing the `type` filter for the side query
        // re-exposes knowledge_resource as a facet checkbox.
        $filter = $this->withKnowledgeResourceExclusion(
            $this->buildMeilisearchFilter((array)($options['filters'] ?? [])),
            $options,
        );
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

        // matchingStrategy lets the caller switch Meilisearch's
        // "which tokens may be dropped if the corpus has no document
        // matching all of them?" behaviour. Three modes:
        //  - "last"      (Meilisearch default): drop trailing tokens.
        //                FE-search returns "tokenA OR tokenB"-style
        //                matches for a two-word query like "foo bar", which
        //                surfaces docs that only mention one of the
        //                words — confusing for users who typed a 2-word
        //                product name expecting an exact-phrase intent.
        //  - "all":      require every query token. Strict AND.
        //  - "frequency": drop the highest-frequency tokens first.
        //                Used by RAG retrieval for verb-led questions.
        // Callers explicitly opt in via the option; otherwise the
        // SearchConfigurationProvider site-setting controls the default.
        $strategy = $this->resolveMatchingStrategy($site, $options);
        if ($strategy !== '') {
            $params['matchingStrategy'] = $strategy;
        }

        $sort = [];
        foreach ($this->normalizeSort($options['sort'] ?? null) as [$field, $direction]) {
            $sort[] = $field . ':' . $direction;
        }
        if ($sort !== []) {
            $params['sort'] = $sort;
        }

        $highlightFields = $this->configProvider->highlightAttributes($site) ?: self::FALLBACK_HIGHLIGHT_FIELDS;
        $cropFields = $this->configProvider->cropAttributes($site) ?: self::FALLBACK_CROP_FIELDS;
        // `content` is the canonical full-text field (page body, file text).
        // Always make it highlightable + croppable so result templates can
        // render a snippet even for docs without a dedicated description /
        // teaser — e.g. Content-Block pages whose body lives only in `content`.
        if (!in_array('content', $highlightFields, true)) {
            $highlightFields[] = 'content';
        }
        $hasContentCrop = false;
        foreach ($cropFields as $cropField) {
            if ($cropField === 'content' || str_starts_with((string)$cropField, 'content:')) {
                $hasContentCrop = true;
                break;
            }
        }
        if (!$hasContentCrop) {
            $cropFields[] = 'content:' . (int)$site->getSettings()->get('meilisearch.frontend.cropLength', 200);
        }
        $params['attributesToHighlight'] = $highlightFields;
        $params['highlightPreTag'] = $this->configProvider->highlightPreTag($site);
        $params['highlightPostTag'] = $this->configProvider->highlightPostTag($site);
        $params['attributesToCrop'] = $cropFields;
        $params['cropMarker'] = $this->configProvider->cropMarker($site);

        $response = $index->search($query, $params);
        $raw = $response->toArray();

        $hits = is_array($raw['hits'] ?? null) ? $raw['hits'] : [];
        $total = (int)($raw['estimatedTotalHits'] ?? $raw['totalHits'] ?? count($hits));
        $facetDistribution = is_array($raw['facetDistribution'] ?? null) ? $raw['facetDistribution'] : [];

        // Disjunctive faceting: when the user has an active filter on a facet
        // attribute, the main query's facetDistribution for that attribute only
        // lists the values matching the filter — i.e. the OTHER checkboxes
        // disappear from the panel and the visitor can't switch within the
        // attribute without first clearing it. Fetch the un-filtered
        // distribution for each user-filtered attribute via side queries
        // (same query, all OTHER filters, no hits requested) and merge them in.
        $userFilters = (array)($options['filters'] ?? []);
        foreach ($facets as $facetField) {
            if (!array_key_exists($facetField, $userFilters)) {
                continue;
            }
            $sideFilters = $userFilters;
            unset($sideFilters[$facetField]);
            $sideParams = [
                'limit' => 0,
                'facets' => [$facetField],
            ];
            // Keep the knowledge_resource exclusion here too — without it the
            // side query (which drops the user's `type` filter) would list
            // knowledge_resource as an available facet value again.
            $sideFilter = $this->withKnowledgeResourceExclusion(
                $this->buildMeilisearchFilter($sideFilters),
                $options,
            );
            if ($sideFilter !== '') {
                $sideParams['filter'] = $sideFilter;
            }
            if ($hybridParams !== null) {
                $sideParams['hybrid'] = $hybridParams;
            }
            try {
                $sideRaw = $index->search($query, $sideParams)->toArray();
                $sideDistribution = is_array($sideRaw['facetDistribution'] ?? null)
                    ? $sideRaw['facetDistribution']
                    : [];
                if (isset($sideDistribution[$facetField]) && is_array($sideDistribution[$facetField])) {
                    $facetDistribution[$facetField] = $sideDistribution[$facetField];
                }
            } catch (\Throwable $e) {
                // Side-query failure (rare — same engine, same index) is
                // non-fatal: the panel just falls back to the filtered
                // distribution for this attribute. Log and continue.
                $this->logger?->warning('Disjunctive facet side-query failed for {field}: {message}', [
                    'field' => $facetField,
                    'message' => $e->getMessage(),
                ]);
            }
        }

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
     * @param array<string,mixed> $options
     */
    private function withKnowledgeResourceExclusion(string $filter, array $options): string
    {
        if ($options['includeKnowledgeResources'] ?? false) {
            return $filter;
        }
        $exclude = 'type != "knowledge_resource"';
        return $filter !== '' ? '(' . $filter . ') AND ' . $exclude : $exclude;
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
        // Reserved key: AccessControlFilter (and any future caller that
        // needs a compound expression like `accessGroups IS EMPTY OR
        // accessGroups IN […]`) stores raw Meilisearch filter strings
        // under `__rawFilters`. They're emitted verbatim, AND-conjoined
        // with the regular field=value filters and with each other.
        $rawFilters = $filters['__rawFilters'] ?? null;
        unset($filters['__rawFilters']);
        if (is_array($rawFilters)) {
            foreach ($rawFilters as $expression) {
                $expression = trim((string)$expression);
                if ($expression !== '') {
                    $parts[] = $expression;
                }
            }
        }
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
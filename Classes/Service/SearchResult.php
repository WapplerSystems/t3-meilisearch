<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

/**
 * Frontend-friendly search result DTO. Decouples Fluid templates from the
 * concrete SEAL/Meilisearch response shape.
 */
final class SearchResult
{
    /**
     * @param list<array<string,mixed>> $hits
     * @param array<string,array<string,int>> $facets attribute => (value => count)
     */
    public function __construct(
        public readonly array $hits = [],
        public readonly int $totalHits = 0,
        public readonly array $facets = [],
        public readonly int $page = 1,
        public readonly int $perPage = 20,
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}
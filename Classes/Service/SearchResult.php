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

    /**
     * Total page count derived from totalHits / perPage. Always >= 1 for a
     * non-empty result, and 0 when there are no hits — that lets templates
     * distinguish "no results" from "page 1 of 1".
     */
    public function getTotalPages(): int
    {
        if ($this->totalHits <= 0 || $this->perPage <= 0) {
            return 0;
        }
        return (int)ceil($this->totalHits / $this->perPage);
    }

    public function getHasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function getHasNextPage(): bool
    {
        return $this->page < $this->getTotalPages();
    }
}
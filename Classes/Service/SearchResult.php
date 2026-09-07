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
     * @param list<QueryAlternative> $alternatives near spellings that DO
     *        have hits, filled by QueryRecoveryService when the query as
     *        typed found nothing. Empty on the happy path.
     * @param string $effectiveQuery the spelling these hits belong to when
     *        an alternative was applied automatically — empty otherwise
     * @param string $originalQuery what the visitor actually typed, kept so
     *        the template can say "no exact hits for X, showing Y"
     * @param string $recovery kind of the applied alternative (see
     *        QueryAlternative::KIND_*), empty when none was applied
     */
    public function __construct(
        public readonly array $hits = [],
        public readonly int $totalHits = 0,
        public readonly array $facets = [],
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly array $alternatives = [],
        public readonly string $effectiveQuery = '',
        public readonly string $originalQuery = '',
        public readonly string $recovery = '',
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Copy of this result carrying the recovery metadata. Used by
     * SearchService after it re-ran the search with a corrected spelling:
     * the hits come from the new query, the reporting must still name the
     * old one.
     *
     * @param list<QueryAlternative> $alternatives
     */
    public function withRecovery(
        array $alternatives,
        string $effectiveQuery = '',
        string $originalQuery = '',
        string $recovery = '',
    ): self {
        return new self(
            hits: $this->hits,
            totalHits: $this->totalHits,
            facets: $this->facets,
            page: $this->page,
            perPage: $this->perPage,
            alternatives: $alternatives,
            effectiveQuery: $effectiveQuery,
            originalQuery: $originalQuery,
            recovery: $recovery,
        );
    }

    /**
     * Copy with a different hit list — for decorating hits (badges,
     * language labels, display partials) without having to enumerate every
     * other field.
     *
     * Enumerating them by hand is how the recovery metadata got silently
     * dropped once already: a PSR-14 listener rebuilt the DTO with the five
     * fields that existed when it was written, and the "no exact match for
     * X" notice disappeared from the frontend while the analytics row still
     * said a correction had been applied. Use this instead of `new
     * SearchResult(...)` whenever only the hits change.
     *
     * @param list<array<string,mixed>> $hits
     */
    public function withHits(array $hits): self
    {
        return new self(
            hits: $hits,
            totalHits: $this->totalHits,
            facets: $this->facets,
            page: $this->page,
            perPage: $this->perPage,
            alternatives: $this->alternatives,
            effectiveQuery: $this->effectiveQuery,
            originalQuery: $this->originalQuery,
            recovery: $this->recovery,
        );
    }

    /**
     * True when the displayed hits belong to a different spelling than the
     * one the visitor typed — the template must then explain itself.
     */
    public function getIsRecovered(): bool
    {
        return $this->recovery !== '' && $this->effectiveQuery !== '';
    }

    public function getHasAlternatives(): bool
    {
        return $this->alternatives !== [];
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
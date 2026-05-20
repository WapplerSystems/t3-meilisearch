<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

/**
 * Dispatched before a search query is executed.
 * Listeners may rewrite the query or augment the options (filters, facets, etc.).
 */
final class BeforeSearchEvent
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $query,
        public array $options,
    ) {}
}
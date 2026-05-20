<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use WapplerSystems\Meilisearch\Service\SearchResult;

/**
 * Dispatched after a search has run.
 * Listeners may inspect or replace the SearchResult before it reaches the controller.
 */
final class AfterSearchEvent
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public readonly string $query,
        public readonly array $options,
        public SearchResult $result,
    ) {}
}
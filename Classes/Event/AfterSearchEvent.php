<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\SearchResult;

/**
 * Dispatched after a search has run.
 * Listeners may inspect or replace the SearchResult before it reaches the controller.
 *
 * The Site reference matches the BeforeSearchEvent shape — needed by
 * analytics listeners that must persist per-site query rows without
 * re-resolving the site from the global request (which is unset in
 * CLI contexts).
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
        public readonly ?Site $site = null,
    ) {}
}
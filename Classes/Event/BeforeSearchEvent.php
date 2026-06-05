<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Dispatched before a search query is executed.
 * Listeners may rewrite the query or augment the options (filters, facets, etc.).
 *
 * The Site reference makes site-aware listeners (per-site stop-words,
 * per-site query expansion) work without re-resolving the site from the
 * global request — important for the CLI ask command which has no request.
 */
final class BeforeSearchEvent
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $query,
        public array $options,
        public readonly ?Site $site = null,
    ) {}
}
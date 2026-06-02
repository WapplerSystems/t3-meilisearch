<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\Context;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Process-scoped stack of the Site currently being indexed.
 *
 * Set by IndexingContextMiddleware around message handling so site-aware
 * services (notably TikaFileExtractor, which needs `meilisearch.tika.*`
 * settings) can pick up the right config without guessing.
 *
 * Stack-based so nested handling (e.g. one handler dispatching another
 * message) restores the previous site on pop.
 */
final class IndexingContext
{
    /** @var list<?Site> */
    private array $stack = [];

    public function push(?Site $site): void
    {
        $this->stack[] = $site;
    }

    public function pop(): void
    {
        array_pop($this->stack);
    }

    public function getCurrentSite(): ?Site
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;

/**
 * Dispatched after a document has been pushed to Meilisearch.
 * Useful for audit logs, queue cleanup, downstream cache invalidation.
 */
final class AfterDocumentIndexedEvent
{
    /**
     * @param array<string,mixed> $document
     */
    public function __construct(
        public readonly SchemaProviderInterface $provider,
        public readonly array $document,
    ) {}
}
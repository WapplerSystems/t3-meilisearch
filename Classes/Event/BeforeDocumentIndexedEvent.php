<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;

/**
 * Dispatched right before a document is pushed to Meilisearch.
 * Listeners may mutate the document (e.g. add enriched fields).
 */
final class BeforeDocumentIndexedEvent
{
    /**
     * @param array<string,mixed> $document
     */
    public function __construct(
        public readonly SchemaProviderInterface $provider,
        public array $document,
    ) {}
}
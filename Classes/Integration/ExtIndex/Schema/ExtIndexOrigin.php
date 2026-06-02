<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\Schema;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;

/**
 * No-op SchemaProvider that exists only to satisfy
 * Before/AfterDocumentIndexedEvent's constructor, which expects a
 * SchemaProviderInterface as the document origin. EXT:index-driven docs
 * come from page/file events, not TCA tables — so this marker carries the
 * type ("pages" / "sys_file") for listeners that filter by origin, and
 * returns empty iterators for the schema-provider iteration methods
 * (full-rebuild bypasses this path entirely).
 */
final class ExtIndexOrigin implements SchemaProviderInterface
{
    public function __construct(private readonly string $table) {}

    public function getTable(): string
    {
        return $this->table;
    }

    public function supports(string $table): bool
    {
        return $table === $this->table;
    }

    public function buildDocumentId(int $uid): string
    {
        return $this->table . '-' . $uid;
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        yield $this->buildDocumentId($uid);
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        return [];
    }

    public function iterateDocuments(Site $site): iterable
    {
        return [];
    }

    public function getAdditionalFields(): array
    {
        return [];
    }
}

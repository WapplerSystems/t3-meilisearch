<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use CmsIg\Seal\Schema\Field\AbstractField;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Contract for record-type-specific indexing.
 *
 * Implementations:
 *  - describe one TCA table (getTable / supports)
 *  - map a record to a document array (fetchDocument / iterateDocuments)
 *  - declare a stable document id prefix (buildDocumentId)
 *  - optionally contribute extra SEAL schema fields (getAdditionalFields)
 *
 * The unified index name is owned by SearchEngineFactory — every provider
 * writes into the same per-site index so faceted multi-type search just works.
 */
interface SchemaProviderInterface
{
    /**
     * TCA table name this provider is responsible for (e.g. "pages").
     */
    public function getTable(): string;

    public function supports(string $table): bool;

    /**
     * Deterministic, collision-free document id for a record.
     * Example: "pages-42", "news-17".
     */
    public function buildDocumentId(int $uid): string;

    /**
     * Fetch a single record and convert it to a document array.
     *
     * @return array<string,mixed>|null
     */
    public function fetchDocument(int $uid): ?array;

    /**
     * Enumerate all eligible records for a full reindex.
     *
     * @return iterable<array<string,mixed>>
     */
    public function iterateDocuments(Site $site): iterable;

    /**
     * Extra SEAL fields this provider needs in the unified index schema,
     * on top of the base set (id, type, uid, pid, language, title, …).
     *
     * @return list<AbstractField>
     */
    public function getAdditionalFields(): array;
}
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
     * Default-language document id for a record. Used as a stable identifier
     * by external code; for providers that produce multiple document
     * variants per record (e.g. one doc per language), the variants share
     * the same prefix and only this single id is the "canonical" one.
     * Example: "pages-42", "news-17", "file-99".
     */
    public function buildDocumentId(int $uid): string;

    /**
     * All document ids that this provider may have written for one record
     * on one site. Removal paths iterate this so per-language variants get
     * cleaned up too.
     *
     * @return iterable<string>
     */
    public function buildDocumentIds(int $uid, Site $site): iterable;

    /**
     * Fetch the document(s) for one record on one site. Providers without
     * per-language variants yield exactly one document; FileSchemaProvider
     * and other multi-language providers yield one per site language.
     *
     * The Site is supplied so providers can read per-site config (e.g. the
     * Tika URL for FileSchemaProvider) and so multi-language overlays can
     * be applied.
     *
     * @return iterable<array<string,mixed>>
     */
    public function fetchDocuments(int $uid, Site $site): iterable;

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
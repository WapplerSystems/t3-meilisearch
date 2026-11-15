<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

/**
 * For providers whose number of documents per record is not fixed — the
 * passages of a file grow and shrink with its content. buildDocumentIds()
 * cannot name them, so after (re)indexing a record the indexer deletes what
 * this filter matches.
 */
interface StaleDocumentsInterface
{
    /**
     * Meilisearch filter matching the record's documents beyond the first
     * $keptCount just written (0: all of them, the record is gone).
     */
    public function staleDocumentsFilter(int $uid, int $keptCount): string;
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Indexing;

/**
 * Tally of what an indexing run actually did. Reported by the CLI
 * commands so an operator can see at a glance whether a reindex cost
 * 45.000 embedding calls or 200 — the difference between "blew the
 * provider quota again" and "routine run".
 */
final class IndexWriteStats
{
    /** Documents handed to the writer. */
    public int $seen = 0;

    /** Documents pushed to Meilisearch (fresh, re-used vector, or no-embedder). */
    public int $written = 0;

    /** Unchanged documents that were not pushed at all. */
    public int $skipped = 0;

    /** Documents written with a vector copied from the existing document. */
    public int $reused = 0;

    /** Documents for which a new embedding was fetched from the provider. */
    public int $embedded = 0;

    /** Documents dropped because embedding failed after all retries. */
    public int $failed = 0;

    public function summary(): string
    {
        return sprintf(
            '%d seen · %d written (%d embedded, %d vectors re-used) · %d unchanged/skipped · %d failed',
            $this->seen,
            $this->written,
            $this->embedded,
            $this->reused,
            $this->skipped,
            $this->failed,
        );
    }
}

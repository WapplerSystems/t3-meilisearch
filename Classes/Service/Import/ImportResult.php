<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import;

/**
 * Outcome of one import run, common shape across all source importers.
 *
 * `extras` is the importer's own metadata bag (e.g. the upload importer
 * stores `uid`, `falUid`, `extractStatus` there). Callers that want
 * importer-specific details type-check on the importer name first.
 */
final readonly class ImportResult
{
    /**
     * @param array<string, mixed> $extras importer-specific extra fields
     */
    public function __construct(
        public int $imported,
        public int $skipped,
        public int $mediaCopied,
        public string $message = '',
        public array $extras = [],
    ) {}

    public function summary(): string
    {
        $base = sprintf('imported %d, skipped %d, media attached %d', $this->imported, $this->skipped, $this->mediaCopied);
        return $this->message !== '' ? $this->message . ' (' . $base . ')' : $base;
    }
}
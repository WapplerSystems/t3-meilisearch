<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\DataHandling;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\IndexerService;

/**
 * Cross-site reindex / remove for a single sys_file row. Used by both
 * the DataHandler hook (BE form edits) and the FAL event listener
 * (storage-level adds, deletes, renames, moves, content rewrites).
 *
 * Files aren't tied to a single site by structure, so every
 * Meilisearch-configured site potentially holds a copy. Per-site
 * failures are logged but don't block the rest — one site's Tika
 * being unreachable shouldn't keep the file out of the other sites'
 * indexes.
 */
final class FileLifecycleHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly IndexerService $indexer,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function reindex(int $fileUid): void
    {
        if ($fileUid <= 0) {
            return;
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $this->indexer->indexRecord('sys_file', $fileUid, $site);
            } catch (\Throwable $e) {
                $this->logger?->warning('Meilisearch reindex of file {uid} failed for site {site}: {message}', [
                    'uid' => $fileUid,
                    'site' => $site->getIdentifier(),
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    public function remove(int $fileUid): void
    {
        if ($fileUid <= 0) {
            return;
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $this->indexer->removeRecord('sys_file', $fileUid, $site);
            } catch (\Throwable $e) {
                $this->logger?->warning('Meilisearch remove of file {uid} failed for site {site}: {message}', [
                    'uid' => $fileUid,
                    'site' => $site->getIdentifier(),
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }
}

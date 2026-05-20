<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\DataHandling;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\IndexerService;

/**
 * Hooks into DataHandler operations to keep Meilisearch documents in sync.
 *
 * Page/news records: resolve the affected site via the row's pid, index once.
 *
 * Files: sys_file isn't bound to a site by structure (the same file may be
 * referenced from any number of pages across any number of sites), and BE
 * metadata edits actually write to sys_file_metadata. We translate metadata
 * writes back to the underlying file uid and then re-index that file into
 * every Meilisearch-configured site.
 *
 * Migrate to PSR-14 events once TYPO3 core ships record lifecycle events.
 */
final class RecordChangeListener
{
    public function __construct(
        private readonly IndexerService $indexer,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param int|string $id
     * @param array<string,mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        $uid = is_numeric($id) ? (int)$id : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($uid <= 0) {
            return;
        }

        if ($table === 'sys_file_metadata') {
            $fileUid = (int)($fieldArray['file'] ?? $this->resolveFileUidFromMetadata($uid));
            if ($fileUid > 0) {
                $this->reindexFileAcrossSites($fileUid);
            }
            return;
        }
        if ($table === 'sys_file') {
            $this->reindexFileAcrossSites($uid);
            return;
        }

        $site = $this->resolveSite($table, $uid, $fieldArray);
        if ($site === null) {
            return;
        }
        $this->indexer->indexRecord($table, $uid, $site);
    }

    /**
     * @param int|string $id
     * @param mixed $value
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        int|string $id,
        mixed $value,
        DataHandler $dataHandler,
    ): void {
        $uid = (int)$id;
        if ($uid <= 0) {
            return;
        }

        if ($table === 'sys_file_metadata') {
            $fileUid = $this->resolveFileUidFromMetadata($uid);
            if ($fileUid > 0) {
                $this->reindexFileAcrossSites($fileUid);
            }
            return;
        }
        if ($table === 'sys_file') {
            if ($command === 'delete') {
                $this->removeFileAcrossSites($uid);
                return;
            }
            $this->reindexFileAcrossSites($uid);
            return;
        }

        $site = $this->resolveSite($table, $uid);
        if ($site === null) {
            return;
        }

        if ($command === 'delete') {
            $this->indexer->removeRecord($table, $uid, $site);
            return;
        }
        if ($command === 'undelete') {
            $this->indexer->indexRecord($table, $uid, $site);
            return;
        }
        // move / copy / localize etc. → re-index the (possibly new) record.
        $this->indexer->indexRecord($table, $uid, $site);
    }

    /**
     * @param array<string,mixed> $fieldArray
     */
    private function resolveSite(string $table, int $uid, array $fieldArray = []): ?Site
    {
        try {
            if ($table === 'pages') {
                return $this->siteFinder->getSiteByPageId($uid);
            }
            $pid = (int)($fieldArray['pid'] ?? 0);
            if ($pid > 0) {
                return $this->siteFinder->getSiteByPageId($pid);
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveFileUidFromMetadata(int $metadataUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $row = $qb->select('file')
            ->from('sys_file_metadata')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($metadataUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return (int)($row['file'] ?? 0);
    }

    private function reindexFileAcrossSites(int $fileUid): void
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $this->indexer->indexRecord('sys_file', $fileUid, $site);
            } catch (\Throwable) {
                // Per-site failure (e.g. Tika down for one site) must not block other sites.
            }
        }
    }

    private function removeFileAcrossSites(int $fileUid): void
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $this->indexer->removeRecord('sys_file', $fileUid, $site);
            } catch (\Throwable) {
                // ignore — best effort
            }
        }
    }
}

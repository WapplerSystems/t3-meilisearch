<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\DataHandling;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\IndexerService;

/**
 * Hooks into DataHandler operations to keep Meilisearch documents in sync.
 *
 * Migrate to PSR-14 events once TYPO3 core ships record lifecycle events.
 */
final class RecordChangeListener
{
    public function __construct(
        private readonly IndexerService $indexer,
        private readonly SiteFinder $siteFinder,
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
}
<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\File as FalFile;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\PathUtility;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;
use WapplerSystems\Meilisearch\Service\Tika\TextExtractor;

/**
 * Persistence + filesystem helpers shared by every HelpDocSourceImporter.
 *
 * Owns the schema knowledge (which table, which fileadmin folder, the
 * FAL storage uid) so importers don't have to. They just call:
 *   - {@see insertHelpdoc()} to write a row
 *   - {@see attachMedia()} to link a FAL file as the row's primary media
 *   - {@see purgeLanguage()} for purge-and-rebuild semantics
 *   - {@see stats()} for the BE dashboard
 *
 * Also exposes the Tika extraction wrapper + utility folder/path helpers.
 */
final class HelpDocRepository
{
    public const HELPDOC_TABLE = 'tx_wsmeilisearch_helpdoc';
    public const FILEADMIN_FOLDER = 'helpdocs';
    public const UPLOADS_SUBFOLDER = 'uploads';
    public const STORAGE_UID = 1;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
        private readonly TextExtractor $textExtractor,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function getStorage(): ResourceStorage
    {
        return $this->storageRepository->findByUid(self::STORAGE_UID);
    }

    public function resolvePath(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $abs = PathUtility::isAbsolutePath($raw) ? $raw : Environment::getProjectPath() . '/' . ltrim($raw, '/');
        return rtrim($abs, '/');
    }

    /**
     * @param array<string, mixed> $row complete helpdoc row, tstamp/crdate set by this method
     * @return int new uid
     */
    public function insertHelpdoc(array $row): int
    {
        $now = time();
        $row['tstamp'] = $now;
        $row['crdate'] = $row['crdate'] ?? $now;
        $conn = $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE);
        $conn->insert(self::HELPDOC_TABLE, $row);
        return (int)$conn->lastInsertId();
    }

    /**
     * Link an existing FAL file to a helpdoc row's `media` field.
     * Used by importers that already obtained a sys_file via
     * {@see addFileFromPath()} or {@see addFileFromUpload()}.
     */
    public function attachMedia(FalFile $falFile, int $helpdocUid, int $languageId, int $pid): void
    {
        $refConn = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $now = time();
        $refConn->insert('sys_file_reference', [
            'pid' => $pid,
            'tstamp' => $now,
            'crdate' => $now,
            'sys_language_uid' => $languageId,
            'uid_local' => $falFile->getUid(),
            'uid_foreign' => $helpdocUid,
            'tablenames' => self::HELPDOC_TABLE,
            'fieldname' => 'media',
            'table_local' => 'sys_file',
            'sorting_foreign' => 1,
        ]);
        $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE)
            ->update(self::HELPDOC_TABLE, ['media' => 1], ['uid' => $helpdocUid]);
    }

    /**
     * Copy a file from $sourceAbs into fileadmin/<root>/<identifier>/
     * (folder name sanitised). Used by the DITA importer for per-topic
     * media folders.
     */
    public function addFileFromPath(string $sourceAbs, string $identifier): FalFile
    {
        $storage = $this->getStorage();
        $root = $this->ensureRootFolder($storage);
        $folderName = $this->sanitiseFolderName($identifier);
        try {
            $sub = $root->getSubfolder($folderName);
        } catch (FolderDoesNotExistException) {
            $sub = $storage->createFolder($folderName, $root);
        }
        return $storage->addFile(
            $sourceAbs,
            $sub,
            basename($sourceAbs),
            DuplicationBehavior::REPLACE,
            false,
        );
    }

    /**
     * Copy a file from $sourceAbs into fileadmin/<root>/uploads/.
     * Used by the SingleFileImporter for editor BE uploads.
     */
    public function addFileToUploads(string $sourceAbs, string $targetFilename): FalFile
    {
        $storage = $this->getStorage();
        $folder = $this->ensureUploadsFolder($storage);
        return $storage->addFile(
            $sourceAbs,
            $folder,
            $targetFilename,
            DuplicationBehavior::RENAME,
            true, // removeOriginal: the temp file is no longer needed
        );
    }

    /**
     * Resolve a FAL folder from a user-supplied identifier. Accepted forms:
     *   - "1:/helpdocs/"       — combined identifier (FAL standard)
     *   - "/helpdocs/"         — assumes default storage uid 1
     *   - "fileadmin/helpdocs" — legacy path-style; we strip "fileadmin/"
     *                            and resolve against the default storage
     *
     * Throws when the storage is unknown or the folder doesn't exist —
     * the importer should let the exception bubble up so the operator
     * gets a clear flash message.
     */
    public function resolveFolder(string $identifier): Folder
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \InvalidArgumentException('Folder identifier is empty.');
        }
        // "<storageUid>:<path>" → use that storage directly.
        if (preg_match('/^(\d+):(.+)$/', $identifier, $m) === 1) {
            $storage = $this->storageRepository->findByUid((int)$m[1]);
            if ($storage === null) {
                throw new \InvalidArgumentException(sprintf('Unknown storage uid %d in folder identifier "%s".', (int)$m[1], $identifier));
            }
            return $storage->getFolder($m[2]);
        }
        // Default storage; strip "fileadmin/" if pasted from URL.
        $path = ltrim($identifier, '/');
        if (str_starts_with($path, 'fileadmin/')) {
            $path = substr($path, strlen('fileadmin/'));
        }
        return $this->getStorage()->getFolder('/' . trim($path, '/'));
    }

    /**
     * Copy a file from $sourceAbs into the given FAL folder. The caller is
     * expected to have validated the folder via {@see resolveFolder()}.
     */
    public function addFileToFolder(string $sourceAbs, Folder $folder, string $targetFilename, bool $removeOriginal = false): FalFile
    {
        return $folder->getStorage()->addFile(
            $sourceAbs,
            $folder,
            $targetFilename,
            DuplicationBehavior::RENAME,
            $removeOriginal,
        );
    }

    public function extractText(FalFile $file): ExtractionResult
    {
        $site = $this->resolveTikaSite();
        if ($site === null) {
            return ExtractionResult::skipped('No site configured to read Tika config from');
        }
        return $this->textExtractor->extract($file, $site);
    }

    public function purgeLanguage(int $languageId): int
    {
        $conn = $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE);
        $rowIds = $conn->createQueryBuilder()
            ->select('uid')
            ->from(self::HELPDOC_TABLE)
            ->where('sys_language_uid = :lang')
            ->setParameter('lang', $languageId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchFirstColumn();
        if ($rowIds !== []) {
            $refConn = $this->connectionPool->getConnectionForTable('sys_file_reference');
            $refConn->createQueryBuilder()
                ->delete('sys_file_reference')
                ->where('tablenames = :t')
                ->andWhere('fieldname = :f')
                ->andWhere('uid_foreign IN (:ids)')
                ->setParameter('t', self::HELPDOC_TABLE)
                ->setParameter('f', 'media')
                ->setParameter('ids', $rowIds, ArrayParameterType::INTEGER)
                ->executeStatement();
        }
        return (int)$conn->delete(self::HELPDOC_TABLE, ['sys_language_uid' => $languageId]);
    }

    /**
     * @return array{languages: array<int, array{total:int, withMedia:int, lastImported:int, types:array<string,int>}>, grandTotal:int}
     */
    public function stats(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::HELPDOC_TABLE);
        $rows = $qb->select('sys_language_uid', 'help_type', 'media', 'tstamp')
            ->from(self::HELPDOC_TABLE)
            ->where($qb->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchAllAssociative();

        $out = ['languages' => [], 'grandTotal' => count($rows)];
        foreach ($rows as $row) {
            $lang = (int)$row['sys_language_uid'];
            if (!isset($out['languages'][$lang])) {
                $out['languages'][$lang] = [
                    'total' => 0,
                    'withMedia' => 0,
                    'lastImported' => 0,
                    'types' => ['concept' => 0, 'task' => 0, 'reference' => 0],
                ];
            }
            $out['languages'][$lang]['total']++;
            if ((int)$row['media'] > 0) {
                $out['languages'][$lang]['withMedia']++;
            }
            $type = (string)$row['help_type'];
            if (!isset($out['languages'][$lang]['types'][$type])) {
                $out['languages'][$lang]['types'][$type] = 0;
            }
            $out['languages'][$lang]['types'][$type]++;
            $tstamp = (int)$row['tstamp'];
            if ($tstamp > $out['languages'][$lang]['lastImported']) {
                $out['languages'][$lang]['lastImported'] = $tstamp;
            }
        }
        ksort($out['languages']);
        return $out;
    }

    public function sanitiseFilename(string $name): string
    {
        $name = (string)preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name);
        $name = trim($name, '._');
        return $name !== '' ? $name : 'upload';
    }

    public function sanitiseIdentifier(string $name): string
    {
        $name = (string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
        $name = trim($name, '_-');
        return $name !== '' ? $name : 'upload';
    }

    private function ensureRootFolder(ResourceStorage $storage): Folder
    {
        try {
            return $storage->getFolder(self::FILEADMIN_FOLDER);
        } catch (FolderDoesNotExistException) {
            return $storage->createFolder(self::FILEADMIN_FOLDER);
        }
    }

    private function ensureUploadsFolder(ResourceStorage $storage): Folder
    {
        $root = $this->ensureRootFolder($storage);
        try {
            return $root->getSubfolder(self::UPLOADS_SUBFOLDER);
        } catch (FolderDoesNotExistException) {
            return $storage->createFolder(self::UPLOADS_SUBFOLDER, $root);
        }
    }

    private function sanitiseFolderName(string $identifier): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]+/', '_', $identifier) ?: 'misc';
    }

    /**
     * Tika needs a Site for config (tika.url, allowed mimes, OCR). The
     * importer pipeline isn't bound to a site so we pick the first one
     * that has Tika configured — config is uniform across installs in
     * practice. Falls back to the first site overall, then null.
     */
    private function resolveTikaSite(): ?Site
    {
        $sites = $this->siteFinder->getAllSites();
        foreach ($sites as $site) {
            if (trim((string)$site->getSettings()->get('meilisearch.tika.url', '')) !== '') {
                return $site;
            }
        }
        return $sites !== [] ? reset($sites) : null;
    }
}
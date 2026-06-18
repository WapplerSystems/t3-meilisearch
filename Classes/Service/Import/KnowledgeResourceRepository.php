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
 * Persistence + filesystem helpers shared by every KnowledgeResourceSourceImporter.
 *
 * Owns the schema knowledge (which table, which fileadmin folder, the
 * FAL storage uid) so importers don't have to. They just call:
 *   - {@see insertKnowledgeResource()} to write a row
 *   - {@see attachMedia()} to link a FAL file as the row's primary media
 *   - {@see purgeLanguage()} for purge-and-rebuild semantics
 *   - {@see stats()} for the BE dashboard
 *
 * Also exposes the Tika extraction wrapper + utility folder/path helpers.
 */
final class KnowledgeResourceRepository
{
    public const HELPDOC_TABLE = 'tx_wsmeilisearch_knowledge_resource';
    public const UPLOADS_SUBFOLDER = 'uploads';
    /**
     * Final fallback when no site is configured and the operator hasn't
     * picked a target folder. Combined identifier so importers can
     * accept the same syntax as the BE picker.
     */
    public const DEFAULT_TARGET_FOLDER = '1:/helpdocs/';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
        private readonly TextExtractor $textExtractor,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Resolve the default FAL target folder. Picks the first site that
     * has `meilisearch.knowledgeResource.fileadminFolder` set and falls back to
     * {@see DEFAULT_TARGET_FOLDER}. The folder is auto-created on first
     * use so first imports work without manual fileadmin prep.
     */
    public function getDefaultTargetFolder(): Folder
    {
        $identifier = self::DEFAULT_TARGET_FOLDER;
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configured = trim((string)$site->getSettings()->get('meilisearch.knowledgeResource.fileadminFolder', ''));
            if ($configured !== '') {
                $identifier = $configured;
                break;
            }
        }
        return $this->resolveOrCreateFolder($identifier);
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
     * @param array<string, mixed> $row complete knowledge resource row, tstamp/crdate set by this method
     * @return int new uid
     */
    public function insertKnowledgeResource(array $row): int
    {
        $now = time();
        $row['tstamp'] = $now;
        $row['crdate'] = $row['crdate'] ?? $now;
        $conn = $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE);
        $conn->insert(self::HELPDOC_TABLE, $row);
        return (int)$conn->lastInsertId();
    }

    /**
     * Whether a row with the given identifier (and same language) already
     * exists. Importers use it to skip duplicate inserts — DITA-OT in
     * particular emits `<basename>-1.html`, `<basename>_2.html` etc. when
     * a topic is referenced from multiple places in the map, which would
     * otherwise create accidental duplicate rows on every re-import.
     */
    public function existsByIdentifierAndLanguage(string $identifier, int $languageId): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::HELPDOC_TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid')
            ->from(self::HELPDOC_TABLE)
            ->where(
                $qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false;
    }

    /**
     * Link an existing FAL file to a knowledge resource row's `media` field.
     * Used by importers that already obtained a sys_file via
     * {@see addFileFromPath()} or {@see addFileFromUpload()}.
     */
    public function attachMedia(FalFile $falFile, int $knowledgeResourceUid, int $languageId, int $pid): void
    {
        $refConn = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $now = time();
        $refConn->insert('sys_file_reference', [
            'pid' => $pid,
            'tstamp' => $now,
            'crdate' => $now,
            'sys_language_uid' => $languageId,
            'uid_local' => $falFile->getUid(),
            'uid_foreign' => $knowledgeResourceUid,
            'tablenames' => self::HELPDOC_TABLE,
            'fieldname' => 'media',
            'table_local' => 'sys_file',
            'sorting_foreign' => 1,
        ]);
        $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE)
            ->update(self::HELPDOC_TABLE, ['media' => 1], ['uid' => $knowledgeResourceUid]);
    }

    /**
     * Copy a file from $sourceAbs into <root>/<identifier>/<filename>
     * (folder name sanitised). The root defaults to the site-configured
     * `meilisearch.knowledgeResource.fileadminFolder`; pass `$rootIdentifier` to
     * override per-call (e.g. operator picked a different folder in the
     * BE form). Used by the DITA importer for per-topic media folders.
     */
    public function addFileFromPath(string $sourceAbs, string $identifier, ?string $rootIdentifier = null): FalFile
    {
        $root = $rootIdentifier !== null && $rootIdentifier !== ''
            ? $this->resolveOrCreateFolder($rootIdentifier)
            : $this->getDefaultTargetFolder();
        $storage = $root->getStorage();
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
     * Copy a file from $sourceAbs into <root>/uploads/. The root
     * defaults to the site-configured `meilisearch.knowledgeResource.fileadminFolder`;
     * pass `$rootIdentifier` to override per-call. Used by the
     * SingleFileImporter for editor BE uploads.
     */
    public function addFileToUploads(string $sourceAbs, string $targetFilename, ?string $rootIdentifier = null): FalFile
    {
        $root = $rootIdentifier !== null && $rootIdentifier !== ''
            ? $this->resolveOrCreateFolder($rootIdentifier)
            : $this->getDefaultTargetFolder();
        $folder = $this->ensureUploadsSubfolder($root);
        return $folder->getStorage()->addFile(
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
        [$storage, $path] = $this->parseFolderIdentifier($identifier);
        return $storage->getFolder($path);
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
        $rows = $qb->select('sys_language_uid', 'resource_type', 'media', 'tstamp')
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
            $type = (string)$row['resource_type'];
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

    /**
     * Like {@see resolveFolder()} but auto-creates the target if it
     * doesn't exist yet. Importers + the default-target resolver use
     * this so first-time setups don't need an operator to pre-create
     * the knowledge resources folder in the file list.
     */
    public function resolveOrCreateFolder(string $identifier): Folder
    {
        [$storage, $path] = $this->parseFolderIdentifier($identifier);
        try {
            return $storage->getFolder($path);
        } catch (FolderDoesNotExistException) {
            // Walk the path segments, creating each missing folder. Done
            // segment-by-segment so createFolder() always gets a parent
            // that already exists.
            $current = $storage->getRootLevelFolder();
            foreach (explode('/', trim($path, '/')) as $segment) {
                if ($segment === '') {
                    continue;
                }
                try {
                    $current = $current->getSubfolder($segment);
                } catch (FolderDoesNotExistException) {
                    $current = $storage->createFolder($segment, $current);
                }
            }
            return $current;
        }
    }

    /**
     * Parse a folder identifier in any of the accepted forms:
     *   - "1:/helpdocs/"       — combined identifier (FAL standard)
     *   - "/helpdocs/"         — assumes default storage uid 1
     *   - "fileadmin/helpdocs" — legacy path-style; stripped of "fileadmin/"
     *                            and resolved against the default storage
     *
     * @return array{0: ResourceStorage, 1: string} storage + leading-slash path
     */
    private function parseFolderIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \InvalidArgumentException('Folder identifier is empty.');
        }
        if (str_starts_with($identifier, 'fileadmin/')) {
            $identifier = '1:/' . ltrim(substr($identifier, strlen('fileadmin/')), '/');
        }
        if (str_starts_with($identifier, '/')) {
            $identifier = '1:' . $identifier;
        }
        if (preg_match('/^(\d+):(.+)$/', $identifier, $m) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid folder identifier "%s". Expected "<storage>:/path/".', $identifier));
        }
        $storage = $this->storageRepository->findByUid((int)$m[1]);
        if ($storage === null) {
            throw new \InvalidArgumentException(sprintf('Unknown storage uid %d in folder identifier "%s".', (int)$m[1], $identifier));
        }
        return [$storage, '/' . trim($m[2], '/')];
    }

    private function ensureUploadsSubfolder(Folder $root): Folder
    {
        try {
            return $root->getSubfolder(self::UPLOADS_SUBFOLDER);
        } catch (FolderDoesNotExistException) {
            return $root->getStorage()->createFolder(self::UPLOADS_SUBFOLDER, $root);
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
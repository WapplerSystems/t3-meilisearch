<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Reads a DITA-OT XHTML drop into tx_wsmeilisearch_helpdoc.
 *
 * Single source of truth for the import logic — both the CLI
 * (ImportHelpDocsCommand) and the BE module (Overview → Help docs
 * tab) drive imports through this service. CLI wraps it with a Symfony
 * progress bar; BE wraps it with a flash-message summary. Both pass an
 * optional onProgress(currentIndex, totalCount, currentIdentifier)
 * callback so the host can report progress in its native style.
 *
 * The importer is idempotent in --purge mode: it drops all rows for the
 * given language plus the matching sys_file_references before re-running,
 * so a re-import never leaves orphan rows or dangling FAL relations.
 */
final class HelpDocImporter
{
    public const HELPDOC_TABLE = 'tx_wsmeilisearch_helpdoc';
    public const FILEADMIN_FOLDER = 'chatbot-hilfe';
    public const STORAGE_UID = 1;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {}

    /**
     * Resolve a user-supplied path: absolute → use as-is; relative →
     * join with project root. Empty input returns empty string.
     */
    public function resolvePath(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $abs = PathUtility::isAbsolutePath($raw) ? $raw : Environment::getProjectPath() . '/' . ltrim($raw, '/');
        return rtrim($abs, '/');
    }

    /**
     * Hard-delete all helpdoc rows for the given language and the
     * matching sys_file_reference entries on the media field. Returns
     * the number of helpdoc rows removed.
     */
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
     * Per-language helpdoc stats for the BE dashboard. Returns:
     *   ['languages' => [<langId> => ['total' => N, 'withMedia' => M,
     *                                 'lastImported' => unix, 'types' => ['concept'=>x,'task'=>y,'reference'=>z]]],
     *    'grandTotal' => N]
     *
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

    /**
     * Run a full import pass. Set $purge=true to wipe the language's
     * existing rows first (the default for CLI/BE workflows). $onProgress
     * fires once per topic with (currentIndex, totalCount, identifier).
     *
     * @param ?callable(int,int,string):void $onProgress
     * @return array{imported:int, skipped:int, mediaCopied:int}
     */
    public function import(
        string $path,
        string $langDir,
        int $languageId,
        int $pid = 0,
        bool $purge = true,
        int $limit = 0,
        ?callable $onProgress = null,
    ): array {
        $rootPath = $this->resolvePath($path);
        $tocFile = $rootPath . '/index.html';
        $topicsDir = $rootPath . '/' . $langDir . '/topics';
        $figuresDir = $rootPath . '/' . $langDir . '/figures';

        if (!is_file($tocFile)) {
            throw new \RuntimeException('TOC file not found: ' . $tocFile);
        }
        if (!is_dir($topicsDir)) {
            throw new \RuntimeException('Topics directory not found: ' . $topicsDir);
        }

        $parentMap = $this->parseTocParents($tocFile, $langDir);

        if ($purge) {
            $this->purgeLanguage($languageId);
        }

        $topicFiles = glob($topicsDir . '/*.html') ?: [];
        sort($topicFiles);
        if ($limit > 0) {
            $topicFiles = array_slice($topicFiles, 0, $limit);
        }
        $total = count($topicFiles);

        $storage = $this->storageRepository->findByUid(self::STORAGE_UID);
        $targetFolder = $this->ensureFolder($storage);

        $imported = 0;
        $mediaCopied = 0;
        $skipped = 0;
        $index = 0;

        foreach ($topicFiles as $topicFile) {
            $index++;
            $row = $this->parseTopic($topicFile, $figuresDir);
            if ($row === null) {
                $skipped++;
                if ($onProgress !== null) {
                    $onProgress($index, $total, '');
                }
                continue;
            }
            $row['sys_language_uid'] = $languageId;
            $row['pid'] = $pid;
            $row['parent_identifier'] = $parentMap[$row['identifier']] ?? '';
            $row['source_path'] = $this->relativeSourcePath($topicFile, $rootPath);
            $row['tstamp'] = time();
            $row['crdate'] = time();
            $mediaSourceAbs = $row['_mediaSourceAbs'] ?? null;
            unset($row['_mediaSourceAbs']);

            $conn = $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE);
            $conn->insert(self::HELPDOC_TABLE, $row);
            $uid = (int)$conn->lastInsertId();
            $imported++;

            if ($mediaSourceAbs !== null && is_file($mediaSourceAbs)) {
                $mediaCopied += $this->attachMedia($storage, $targetFolder, $mediaSourceAbs, $row['identifier'], $uid, $languageId, $pid);
            }
            if ($onProgress !== null) {
                $onProgress($index, $total, (string)$row['identifier']);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'mediaCopied' => $mediaCopied];
    }

    /**
     * Parses index.html for the topic tree and builds a flat
     * identifier → parent-identifier map. Identifier is derived from the
     * href filename (the .html stem is the natural key).
     *
     * @return array<string, string>
     */
    private function parseTocParents(string $tocFile, string $langDir): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML(file_get_contents($tocFile) ?: '', LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $map = [];
        $nodes = $xp->query('//li[contains(@class, "topicref")]/a[starts-with(@href, "' . $langDir . '/topics/")]');
        if (!$nodes instanceof \DOMNodeList) {
            return $map;
        }
        foreach ($nodes as $a) {
            /** @var \DOMElement $a */
            $childId = $this->identifierFromHref($a->getAttribute('href'));
            if ($childId === '') {
                continue;
            }
            $ancestor = $xp->query('ancestor::li[contains(@class, "topicref")]/a[starts-with(@href, "' . $langDir . '/topics/")][1]', $a)->item(0);
            if ($ancestor instanceof \DOMElement) {
                $parentId = $this->identifierFromHref($ancestor->getAttribute('href'));
                if ($parentId !== '' && $parentId !== $childId) {
                    $map[$childId] = $parentId;
                }
            }
        }
        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseTopic(string $topicFile, string $figuresDir): ?array
    {
        $content = file_get_contents($topicFile);
        if ($content === false || $content === '') {
            return null;
        }
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($content, LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        // DITA-OT happily emits multiple HTML stubs with the same
        // DC.identifier when a topic is included from several TOC
        // branches. The filename is the natural unique key (also what
        // the TOC anchors point at).
        $identifier = pathinfo($topicFile, PATHINFO_FILENAME);
        $title = $this->metaContent($xp, 'DC.title');
        if ($title === '') {
            $h1 = $xp->query('//body//h1[contains(@class, "topictitle")]')->item(0);
            $title = $h1 instanceof \DOMElement ? trim($h1->textContent) : '';
        }
        $abstract = $this->metaContent($xp, 'abstract') ?: $this->metaContent($xp, 'description');
        $helpType = $this->metaContent($xp, 'DC.type') ?: 'concept';
        $body = $this->extractBody($xp);
        if ($title === '' && trim($body) === '') {
            return null;
        }
        $mediaPath = $this->firstMediaPath($xp, dirname($topicFile));
        return [
            'identifier' => substr($identifier, 0, 190),
            'title' => substr($title, 0, 512),
            'abstract' => $abstract,
            'body' => $body,
            'help_type' => $helpType,
            '_mediaSourceAbs' => $mediaPath,
        ];
    }

    private function metaContent(\DOMXPath $xp, string $name): string
    {
        $node = $xp->query(sprintf('//head/meta[@name=%s]', $this->quoteXpath($name)))->item(0);
        if (!$node instanceof \DOMElement) {
            return '';
        }
        return trim($node->getAttribute('content'));
    }

    /**
     * Plain-text body: take everything under <body>, strip nav/script/
     * style/header/footer, collapse whitespace. Preserve paragraph breaks
     * via double newlines.
     */
    private function extractBody(\DOMXPath $xp): string
    {
        $body = $xp->query('//body')->item(0);
        if (!$body instanceof \DOMElement) {
            return '';
        }
        foreach (['.//script', './/style', './/nav', './/header', './/footer'] as $strip) {
            foreach (iterator_to_array($xp->query($strip, $body)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $html = $body->ownerDocument->saveHTML($body);
        $padded = preg_replace('#</(p|div|section|li|h[1-6]|td|tr)>#u', "$0\n", (string)$html) ?? (string)$html;
        $text = strip_tags($padded);
        $text = (string)preg_replace('/\h+/u', ' ', $text);
        $text = (string)preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    private function firstMediaPath(\DOMXPath $xp, string $topicDir): ?string
    {
        $candidates = $xp->query('//body//img/@src | //body//source/@src | //body//video/@src');
        if (!$candidates instanceof \DOMNodeList) {
            return null;
        }
        foreach ($candidates as $attr) {
            $src = trim($attr->nodeValue);
            if ($src === '' || str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '//')) {
                continue;
            }
            $abs = realpath($topicDir . '/' . $src);
            if ($abs !== false && is_file($abs)) {
                return $abs;
            }
        }
        return null;
    }

    private function identifierFromHref(string $href): string
    {
        return preg_replace('/\.html?$/i', '', basename($href)) ?? '';
    }

    private function quoteXpath(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
    }

    private function relativeSourcePath(string $topicFile, string $rootPath): string
    {
        if (str_starts_with($topicFile, $rootPath . '/')) {
            return ltrim(substr($topicFile, strlen($rootPath)), '/');
        }
        return basename($topicFile);
    }

    private function ensureFolder(ResourceStorage $storage): Folder
    {
        try {
            return $storage->getFolder(self::FILEADMIN_FOLDER);
        } catch (FolderDoesNotExistException) {
            return $storage->createFolder(self::FILEADMIN_FOLDER);
        }
    }

    private function attachMedia(
        ResourceStorage $storage,
        Folder $rootFolder,
        string $sourceAbs,
        string $identifier,
        int $helpdocUid,
        int $languageId,
        int $pid,
    ): int {
        $folderName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $identifier) ?: 'misc';
        try {
            $subFolder = $rootFolder->getSubfolder($folderName);
        } catch (FolderDoesNotExistException) {
            $subFolder = $storage->createFolder($folderName, $rootFolder);
        }
        $falFile = $storage->addFile(
            $sourceAbs,
            $subFolder,
            basename($sourceAbs),
            \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::REPLACE,
            false,
        );

        $refConn = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $refConn->insert('sys_file_reference', [
            'pid' => $pid,
            'tstamp' => time(),
            'crdate' => time(),
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
        return 1;
    }
}
<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Imports DITA-OT generated XHTML help into tx_wsmeilisearch_helpdoc.
 *
 * Workflow per run (purge-and-rebuild mode is default — full reimport):
 *   1. Truncate tx_wsmeilisearch_helpdoc rows for the chosen language
 *   2. Parse <path>/index.html (the TOC) → identifier → parent-identifier map
 *   3. Iterate <path>/<langDir>/topics/*.html, extract DITA metadata + body
 *   4. For each topic, find the first referenced image / video, copy it
 *      to fileadmin/chatbot-hilfe/<identifier>/ and create a
 *      sys_file_reference on the helpdoc row's `media` field
 *   5. Insert the row with sys_language_uid set
 *
 * Multi-language: re-run with --language=<id> --langDir=<en|fr|nl> pointing
 * at the sibling EN_xhtml / FR_xhtml folders. Each language's import scope
 * is the SAME identifier — l10n_parent linking is left as a follow-up
 * (currently each language stands alone).
 */
#[AsCommand(
    name: 'ws_meilisearch:import-chatbot-hilfe',
    description: 'Import DITA-OT XHTML help topics into tx_wsmeilisearch_helpdoc + Meilisearch.',
)]
final class ImportChatbotHilfeCommand extends Command
{
    private const HELPDOC_TABLE = 'tx_wsmeilisearch_helpdoc';
    private const FILEADMIN_FOLDER = 'chatbot-hilfe';
    private const STORAGE_UID = 1;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
        private readonly ResourceFactory $resourceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the DITA-OT root folder (the one containing index.html + the language subdir). Relative to project root or absolute.',
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'TYPO3 sys_language_uid for the imported records.',
                '0',
            )
            ->addOption(
                'langDir',
                null,
                InputOption::VALUE_REQUIRED,
                'Subdirectory name under --path that contains the topics/ folder.',
                'de',
            )
            ->addOption(
                'pid',
                null,
                InputOption::VALUE_REQUIRED,
                'Storage pid for the new records (default 0 = site root).',
                '0',
            )
            ->addOption(
                'no-purge',
                null,
                InputOption::VALUE_NONE,
                'Skip the truncate step. Default is purge-and-rebuild — re-runs are idempotent because the importer drops everything for the given language first.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Only import the first N topics. Useful for quick smoke tests.',
                '0',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawPath = (string)$input->getOption('path');
        if ($rawPath === '') {
            $io->error('--path is required.');
            return Command::FAILURE;
        }
        $rootPath = $this->resolvePath($rawPath);
        $langDir = (string)$input->getOption('langDir');
        $languageId = (int)$input->getOption('language');
        $pid = (int)$input->getOption('pid');
        $limit = max(0, (int)$input->getOption('limit'));
        $purge = !$input->getOption('no-purge');

        $tocFile = $rootPath . '/index.html';
        $topicsDir = $rootPath . '/' . $langDir . '/topics';
        $figuresDir = $rootPath . '/' . $langDir . '/figures';

        if (!is_file($tocFile)) {
            $io->error('TOC file not found: ' . $tocFile);
            return Command::FAILURE;
        }
        if (!is_dir($topicsDir)) {
            $io->error('Topics directory not found: ' . $topicsDir);
            return Command::FAILURE;
        }

        $io->section(sprintf('Import from %s (language=%d, pid=%d)', $rootPath, $languageId, $pid));

        // 1. Build the identifier → parent-identifier map from TOC
        $io->writeln('Parsing TOC…');
        $parentMap = $this->parseTocParents($tocFile, $langDir);
        $io->writeln(sprintf('  found %d topic→parent entries', count($parentMap)));

        // 2. Purge
        if ($purge) {
            $deleted = $this->purgeLanguage($languageId);
            $io->writeln(sprintf('Purged %d existing rows for language=%d', $deleted, $languageId));
        }

        // 3. Iterate topic files
        $topicFiles = glob($topicsDir . '/*.html') ?: [];
        sort($topicFiles);
        if ($limit > 0) {
            $topicFiles = array_slice($topicFiles, 0, $limit);
        }
        $io->writeln(sprintf('Importing %d topic(s)…', count($topicFiles)));

        $storage = $this->storageRepository->findByUid(self::STORAGE_UID);
        $targetFolder = $this->ensureFolder($storage);

        $imported = 0;
        $mediaCopied = 0;
        $skipped = 0;
        $progressBar = $io->createProgressBar(count($topicFiles));
        $progressBar->start();

        foreach ($topicFiles as $topicFile) {
            $progressBar->advance();
            $row = $this->parseTopic($topicFile, $figuresDir);
            if ($row === null) {
                $skipped++;
                continue;
            }
            $row['sys_language_uid'] = $languageId;
            $row['pid'] = $pid;
            $row['parent_identifier'] = $parentMap[$row['identifier']] ?? '';
            $row['source_path'] = $this->relativeSourcePath($topicFile, $rootPath);

            // Insert row first so we have a uid for sys_file_reference
            $row['tstamp'] = time();
            $row['crdate'] = time();
            $mediaSourceAbs = $row['_mediaSourceAbs'] ?? null;
            unset($row['_mediaSourceAbs']);

            $conn = $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE);
            $conn->insert(self::HELPDOC_TABLE, $row);
            $uid = (int)$conn->lastInsertId();
            $imported++;

            if ($mediaSourceAbs !== null && is_file($mediaSourceAbs)) {
                $copiedCount = $this->attachMedia($storage, $targetFolder, $mediaSourceAbs, $row['identifier'], $uid, $languageId, $pid);
                $mediaCopied += $copiedCount;
            }
        }
        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf(
            'Imported %d topic(s) (%d skipped, %d media files attached). Run `ws_meilisearch:reindex` to push them to Meilisearch.',
            $imported,
            $skipped,
            $mediaCopied,
        ));
        return Command::SUCCESS;
    }

    /**
     * Resolve a user-supplied path: absolute → use as-is; relative → join with project root.
     */
    private function resolvePath(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $abs = PathUtility::isAbsolutePath($raw) ? $raw : Environment::getProjectPath() . '/' . ltrim($raw, '/');
        return rtrim($abs, '/');
    }

    /**
     * Parses index.html for the topic tree and builds a flat
     * identifier → parent-identifier map. Identifier is derived from the
     * href filename (the .html stem is the DC.identifier).
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
        // Each topicref <li><a href="de/topics/X.html">…</a> nested under
        // another topicref carries the parent relationship. Walk every
        // anchor that points into the topics/ folder.
        $nodes = $xp->query('//li[contains(@class, "topicref")]/a[starts-with(@href, "' . $langDir . '/topics/")]');
        if (!$nodes instanceof \DOMNodeList) {
            return $map;
        }
        foreach ($nodes as $a) {
            /** @var \DOMElement $a */
            $href = $a->getAttribute('href');
            $childId = $this->identifierFromHref($href);
            if ($childId === '') {
                continue;
            }
            // Walk up to the nearest ancestor topicref-<li>'s anchor.
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
     * Parse one topic file. Returns the row data ready for insert + a
     * private `_mediaSourceAbs` key with the absolute path to the topic's
     * primary image / video (or null if none).
     *
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

        // The filename is the natural unique key here, NOT the DITA
        // DC.identifier — DITA-OT happily produces multiple HTML stubs
        // with the same DC.identifier when a topic is included from
        // several TOC branches (conref / reuse), e.g.
        //   ausg_c_exportDaemmwerk_BU_ausg.html
        //   ausg_c_exportDaemmwerk_BU_ausg-1.html
        //   ausg_c_exportDaemmwerk_BU_ausg_2.html
        // all carry DC.identifier="ausg_c_exportDaemmwerk_BU". The
        // filename is also what the TOC anchors point at, so using it
        // here also aligns the parent-identifier lookup.
        $identifier = pathinfo($topicFile, PATHINFO_FILENAME);
        $title = $this->metaContent($xp, 'DC.title');
        if ($title === '') {
            $h1 = $xp->query('//body//h1[contains(@class, "topictitle")]')->item(0);
            $title = $h1 instanceof \DOMElement ? trim($h1->textContent) : '';
        }
        $abstract = $this->metaContent($xp, 'abstract') ?: $this->metaContent($xp, 'description');
        $helpType = $this->metaContent($xp, 'DC.type') ?: 'concept';

        $body = $this->extractBody($xp);

        // Skip empties — topics with no real text are pure TOC stubs.
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
     * Plain-text body: take everything under <body>, strip <head>/<nav>/
     * <script>/<style>, collapse whitespace. Preserve paragraph breaks
     * via double newlines so the LLM context window keeps the structure
     * a human would expect.
     */
    private function extractBody(\DOMXPath $xp): string
    {
        $body = $xp->query('//body')->item(0);
        if (!$body instanceof \DOMElement) {
            return '';
        }
        // Drop nodes we never want in the indexed text.
        foreach (['.//script', './/style', './/nav', './/header', './/footer'] as $strip) {
            foreach (iterator_to_array($xp->query($strip, $body)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        // Inject newlines at block boundaries so strip_tags doesn't fuse paragraphs.
        $html = $body->ownerDocument->saveHTML($body);
        $padded = preg_replace('#</(p|div|section|li|h[1-6]|td|tr)>#u', "$0\n", (string)$html) ?? (string)$html;
        $text = strip_tags($padded);
        $text = (string)preg_replace('/\h+/u', ' ', $text);
        $text = (string)preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    /**
     * Find the first <img|video|source> that resolves to a file we can
     * actually copy. Returns an absolute path on disk or null.
     */
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
        $basename = basename($href);
        return preg_replace('/\.html?$/i', '', $basename) ?? '';
    }

    private function quoteXpath(string $value): string
    {
        // Quote safely for XPath 1.0 (no escape sequences exist).
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

    private function purgeLanguage(int $languageId): int
    {
        $conn = $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE);
        // Hard-delete: rows are managed by the importer, no editor curation
        // to preserve. Also drop any FAL references to the wiped rows.
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
                ->setParameter('ids', $rowIds, \Doctrine\DBAL\ArrayParameterType::INTEGER)
                ->executeStatement();
        }
        return (int)$conn->delete(self::HELPDOC_TABLE, ['sys_language_uid' => $languageId]);
    }

    private function ensureFolder($storage): \TYPO3\CMS\Core\Resource\Folder
    {
        try {
            return $storage->getFolder(self::FILEADMIN_FOLDER);
        } catch (FolderDoesNotExistException) {
            return $storage->createFolder(self::FILEADMIN_FOLDER);
        }
    }

    /**
     * Copy a media file into fileadmin/<FILEADMIN_FOLDER>/<identifier>/
     * and create a sys_file_reference linking it to the helpdoc row.
     */
    private function attachMedia(
        $storage,
        \TYPO3\CMS\Core\Resource\Folder $rootFolder,
        string $sourceAbs,
        string $identifier,
        int $helpdocUid,
        int $languageId,
        int $pid,
    ): int {
        // Sanitise identifier into a folder name (alnum + dash/underscore).
        $folderName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $identifier) ?: 'misc';
        try {
            $subFolder = $rootFolder->getSubfolder($folderName);
        } catch (FolderDoesNotExistException) {
            $subFolder = $storage->createFolder($folderName, $rootFolder);
        }
        $fileName = basename($sourceAbs);
        // Use ResourceFactory to add the file via FAL — that creates
        // the sys_file row + writes the file into the storage. We use
        // `addFile` with overwrite semantics so re-runs replace existing
        // copies cleanly.
        $falFile = $storage->addFile(
            $sourceAbs,
            $subFolder,
            $fileName,
            \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::REPLACE,
            false, // keepOriginal=false → move? actually we want the original on disk to stay, FAL copies internally
        );

        // sys_file_reference linking helpdoc.media → sys_file
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

        // Update helpdoc.media counter to 1.
        $this->connectionPool->getConnectionForTable(self::HELPDOC_TABLE)
            ->update(self::HELPDOC_TABLE, ['media' => 1], ['uid' => $helpdocUid]);

        return 1;
    }
}
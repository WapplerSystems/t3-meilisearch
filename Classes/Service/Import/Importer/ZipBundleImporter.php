<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import\Importer;

use Psr\Http\Message\UploadedFileInterface;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceRepository;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\ImportResult;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;

/**
 * Batch-import a ZIP bundle of mixed documents (PDF / DOCX / HTML /
 * MD / TXT / Office / EPUB / …). Operator uploads one .zip, the
 * importer extracts each entry to a temp dir, copies the file into
 * fileadmin via FAL, runs Tika to fill the body, and creates one
 * knowledge resource per file.
 *
 * Two safety guards:
 *   - Zip-slip rejection: any entry name containing ".." or starting
 *     with "/" or "\0" is dropped before extraction.
 *   - Hard cap on entry count ({@see MAX_ENTRIES}) so a zip bomb /
 *     accidentally-huge bundle aborts cleanly instead of consuming
 *     hours of FAL+Tika round-trips.
 *
 * Tika decides what's indexable via its own mime allowlist — files
 * outside that list become knowledge resources with empty body (still searchable
 * by title). To skip those entirely, configure
 * `meilisearch.tika.allowedMimeTypes` to be permissive.
 *
 * Folder layout in fileadmin: by default the zip is flattened (every
 * file lands directly in <targetFolder>/zips/<filename>). Operators who
 * want to preserve the zip's directory structure tick "Preserve
 * subfolders".
 */
final class ZipBundleImporter implements KnowledgeResourceSourceImporter
{
    /** Anything larger than this in the zip is treated as suspicious. */
    private const MAX_ENTRIES = 1000;

    /** Subfolder under the chosen target where extracted files land. */
    private const ZIPS_SUBFOLDER = 'zips';

    public function __construct(
        private readonly KnowledgeResourceRepository $repository,
    ) {}

    public function name(): string
    {
        return 'zip-bundle';
    }

    public function label(): string
    {
        return 'ZIP bundle upload';
    }

    public function description(): string
    {
        return 'Upload one .zip containing many documents — each entry becomes its own knowledge resource.';
    }

    public function describeFields(): array
    {
        return [
            ['name' => 'upload', 'label' => 'ZIP file', 'type' => 'file', 'required' => true,
             'help' => 'A .zip archive containing the documents to import.'],
            ['name' => 'language', 'label' => 'Target sys_language_uid', 'type' => 'language', 'default' => 0],
            ['name' => 'resource_type', 'label' => 'Document kind', 'type' => 'select', 'default' => 'reference',
             'options' => ['reference' => 'reference', 'concept' => 'concept', 'task' => 'task', 'upload' => 'upload']],
            ['name' => 'targetFolder', 'label' => 'Target folder', 'type' => 'folder',
             'help' => 'Where the extracted files land in fileadmin. Empty = site default (meilisearch.knowledgeResource.fileadminFolder). A "zips/" subfolder is added automatically.'],
            ['name' => 'preserveSubfolders', 'label' => 'Preserve subfolders', 'type' => 'checkbox', 'default' => false,
             'help' => 'Off (default) flattens the zip — every file lands directly in zips/. On mirrors the zip\'s directory structure.'],
            ['name' => 'titleFromFilename', 'label' => 'Use filename as title', 'type' => 'checkbox', 'default' => true,
             'help' => 'When off, every knowledge resource starts with an empty title.'],
        ];
    }

    public function import(array $config, ?callable $onProgress = null): ImportResult
    {
        $upload = $config['upload'] ?? null;
        if (!$upload instanceof UploadedFileInterface) {
            throw new \RuntimeException('upload is required and must be a PSR-7 UploadedFileInterface');
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed with PHP error code ' . $upload->getError());
        }
        $clientFilename = (string)$upload->getClientFilename();
        if ($clientFilename === '') {
            throw new \RuntimeException('Uploaded file has no name.');
        }

        $languageId = (int)($config['language'] ?? 0);
        $resourceType = trim((string)($config['resource_type'] ?? 'reference'));
        if (!in_array($resourceType, ['reference', 'concept', 'task', 'upload'], true)) {
            $resourceType = 'reference';
        }
        $targetRoot = trim((string)($config['targetFolder'] ?? ''));
        $preserveSubfolders = (bool)($config['preserveSubfolders'] ?? false);
        $titleFromFilename = (bool)($config['titleFromFilename'] ?? true);

        // Stage the upload to a temp file so ZipArchive::open() can read it.
        $tmpZip = sys_get_temp_dir() . '/wsmzip_' . bin2hex(random_bytes(8)) . '.zip';
        $bytes = (string)$upload->getStream()->getContents();
        if ($bytes === '') {
            throw new \RuntimeException('Uploaded zip is empty.');
        }
        if (file_put_contents($tmpZip, $bytes) === false) {
            throw new \RuntimeException('Cannot stage upload to ' . $tmpZip);
        }

        $extractDir = sys_get_temp_dir() . '/wsmzip_extract_' . bin2hex(random_bytes(8));
        if (!mkdir($extractDir, 0700, true) && !is_dir($extractDir)) {
            @unlink($tmpZip);
            throw new \RuntimeException('Cannot create temp dir ' . $extractDir);
        }

        try {
            $entries = $this->safeListAndExtract($tmpZip, $extractDir);
            $total = count($entries);
            if ($total === 0) {
                return new ImportResult(0, 0, 0, sprintf('"%s" contained no importable entries', $clientFilename));
            }

            // Resolve the FAL target (auto-creates the folder + the
            // zips/ subfolder if missing) once up-front so we don't
            // re-resolve per entry.
            $rootIdentifier = $targetRoot !== ''
                ? rtrim($targetRoot, '/') . '/' . self::ZIPS_SUBFOLDER . '/'
                : $this->repository->getDefaultTargetFolder()->getCombinedIdentifier() . self::ZIPS_SUBFOLDER . '/';
            $targetFolder = $this->repository->resolveOrCreateFolder($rootIdentifier);

            $imported = 0;
            $skipped = 0;
            $mediaCopied = 0;
            $index = 0;

            foreach ($entries as $entry) {
                $index++;
                $absSource = $entry['abs'];
                $relPath = $entry['rel'];

                if (!is_file($absSource)) {
                    $skipped++;
                    continue;
                }

                $baseName = basename($relPath);
                $title = $titleFromFilename ? pathinfo($baseName, PATHINFO_FILENAME) : '';

                // Compose the final filename under the FAL folder. With
                // preserveSubfolders=true we keep the zip's relative path
                // so two "intro.pdf" in different subdirs don't collide
                // (FAL also auto-renames on conflict, but this is nicer).
                $targetFilename = $this->repository->sanitiseFilename(
                    $preserveSubfolders ? $this->flattenPath($relPath) : $baseName
                );

                $falFile = $this->repository->addFileToFolder(
                    $absSource,
                    $targetFolder,
                    $targetFilename,
                );

                $extracted = $this->repository->extractText($falFile);
                $body = $extracted->status === ExtractionResult::SUCCESS ? $extracted->text : '';

                $identifier = $this->repository->sanitiseIdentifier(pathinfo($baseName, PATHINFO_FILENAME))
                    . '-f' . $falFile->getUid();

                $knowledgeResourceUid = $this->repository->insertKnowledgeResource([
                    'pid' => 0,
                    'sys_language_uid' => $languageId,
                    'identifier' => substr($identifier, 0, 190),
                    'title' => substr($title, 0, 512),
                    'abstract' => '',
                    'body' => $body,
                    'resource_type' => $resourceType,
                    'parent_identifier' => '',
                    'source_path' => (string)$falFile->getPublicUrl(),
                    'media' => 0,
                ]);
                $this->repository->attachMedia($falFile, $knowledgeResourceUid, $languageId, 0);
                $imported++;
                $mediaCopied++;

                if ($onProgress !== null) {
                    $onProgress($index, $total, $relPath);
                }
            }

            return new ImportResult(
                imported: $imported,
                skipped: $skipped,
                mediaCopied: $mediaCopied,
                message: sprintf('Zip import of "%s" (language %d)', $clientFilename, $languageId),
            );
        } finally {
            @unlink($tmpZip);
            $this->recursiveDelete($extractDir);
        }
    }

    /**
     * Open the zip, drop entries that look hostile (path traversal,
     * absolute paths, null bytes, dotfiles, oversize entry count),
     * extract the survivors to $extractDir, and return [abs, rel] pairs.
     *
     * @return list<array{abs:string, rel:string}>
     */
    private function safeListAndExtract(string $zipPath, string $extractDir): array
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException('Cannot open zip (code ' . $opened . ')');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new \RuntimeException(sprintf('Zip has %d entries (cap %d) — refusing as likely zip bomb.', $zip->numFiles, self::MAX_ENTRIES));
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/')) {
                continue; // directory entry
            }
            $base = basename($name);
            if (str_starts_with($base, '.')) {
                continue; // hidden file (macOS __MACOSX, .DS_Store, etc.)
            }
            if (str_contains($name, "\0") || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                continue; // zip-slip attempt
            }
            // Extract this single entry to a safe path under $extractDir.
            $safeRel = str_replace(['\\', '//'], '/', $name);
            $absTarget = $extractDir . '/' . $safeRel;
            $absDir = dirname($absTarget);
            if (!is_dir($absDir) && !mkdir($absDir, 0700, true) && !is_dir($absDir)) {
                continue;
            }
            $stream = $zip->getStream($name);
            if (!is_resource($stream)) {
                continue;
            }
            $written = file_put_contents($absTarget, stream_get_contents($stream));
            fclose($stream);
            if ($written === false) {
                continue;
            }
            $entries[] = ['abs' => $absTarget, 'rel' => $safeRel];
        }
        $zip->close();
        return $entries;
    }

    /**
     * Squash a relative path into a single safe filename so flattened
     * imports keep subdir context as a prefix. e.g.
     * "handbooks/2026/intro.pdf" → "handbooks_2026_intro.pdf"
     */
    private function flattenPath(string $rel): string
    {
        return str_replace(['/', '\\'], '_', trim($rel, '/'));
    }

    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}

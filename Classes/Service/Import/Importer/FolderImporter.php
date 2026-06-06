<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import\Importer;

use TYPO3\CMS\Core\Resource\File as FalFile;
use TYPO3\CMS\Core\Resource\Folder;
use WapplerSystems\Meilisearch\Service\Import\HelpDocRepository;
use WapplerSystems\Meilisearch\Service\Import\HelpDocSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\ImportResult;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;

/**
 * Batch-import every file already present in a FAL folder as one
 * helpdoc per file. The folder lives wherever the operator chose
 * (default storage's fileadmin, or any other storage they have access
 * to) — sys_file rows for those files may already exist; we re-use
 * them rather than copying.
 *
 * Use cases: an editor uploaded a stack of PDFs into
 * fileadmin/handbooks/ via FileList, or a sync process drops Markdown
 * into fileadmin/imports/ daily. The importer points at that folder
 * and turns each file into a searchable helpdoc — title from the file
 * name (sans extension), body from Tika extraction.
 *
 * Subfolders are walked iff `recursive` is true. Hidden files (those
 * starting with ".") are skipped. The file's existing FAL identifier
 * is used; no copy happens, no rename — which keeps the operator's
 * mental model intact (the file in fileadmin IS the helpdoc's media).
 */
final class FolderImporter implements HelpDocSourceImporter
{
    public function __construct(
        private readonly HelpDocRepository $repository,
    ) {}

    public function name(): string
    {
        return 'folder';
    }

    public function label(): string
    {
        return 'FAL folder';
    }

    public function description(): string
    {
        return 'Walk a fileadmin folder and create one helpdoc per file. Reuses existing FAL records.';
    }

    public function describeFields(): array
    {
        return [
            ['name' => 'folder', 'label' => 'Source folder', 'type' => 'folder', 'required' => true,
             'help' => 'FAL folder containing the files to import. Existing sys_file records are reused; nothing is copied.'],
            ['name' => 'recursive', 'label' => 'Include subfolders', 'type' => 'checkbox', 'default' => false,
             'help' => 'Walk into subfolders recursively. Off by default — keeps re-runs predictable.'],
            ['name' => 'language', 'label' => 'Target sys_language_uid', 'type' => 'language', 'default' => 0],
            ['name' => 'pid', 'label' => 'Storage pid', 'type' => 'text', 'default' => '0',
             'help' => 'Page id where the records live. 0 = site root.'],
            ['name' => 'help_type', 'label' => 'Document kind', 'type' => 'select', 'default' => 'reference',
             'options' => ['reference' => 'reference', 'concept' => 'concept', 'task' => 'task', 'upload' => 'upload']],
            ['name' => 'titleFromFilename', 'label' => 'Use filename as title', 'type' => 'checkbox', 'default' => true,
             'help' => 'When off, every helpdoc starts with an empty title — only useful if you plan to edit them manually afterwards.'],
        ];
    }

    public function import(array $config, ?callable $onProgress = null): ImportResult
    {
        $folderIdentifier = trim((string)($config['folder'] ?? ''));
        if ($folderIdentifier === '') {
            throw new \RuntimeException('folder is required');
        }
        $folder = $this->repository->resolveFolder($folderIdentifier);
        $recursive = (bool)($config['recursive'] ?? false);
        $languageId = (int)($config['language'] ?? 0);
        $pid = (int)($config['pid'] ?? 0);
        $helpType = trim((string)($config['help_type'] ?? 'reference'));
        if (!in_array($helpType, ['reference', 'concept', 'task', 'upload'], true)) {
            $helpType = 'reference';
        }
        $titleFromFilename = (bool)($config['titleFromFilename'] ?? true);

        $files = $this->collectFiles($folder, $recursive);
        $total = count($files);
        $imported = 0;
        $mediaCopied = 0;
        $skipped = 0;
        $index = 0;

        foreach ($files as $falFile) {
            $index++;
            $clientName = $falFile->getName();
            if ($clientName === '' || str_starts_with($clientName, '.')) {
                $skipped++;
                if ($onProgress !== null) {
                    $onProgress($index, $total, $clientName);
                }
                continue;
            }
            $title = $titleFromFilename ? pathinfo($clientName, PATHINFO_FILENAME) : '';
            $identifier = $this->repository->sanitiseIdentifier(pathinfo($clientName, PATHINFO_FILENAME))
                . '-f' . $falFile->getUid();

            $extracted = $this->repository->extractText($falFile);
            $body = $extracted->status === ExtractionResult::SUCCESS ? $extracted->text : '';

            $helpdocUid = $this->repository->insertHelpdoc([
                'pid' => $pid,
                'sys_language_uid' => $languageId,
                'identifier' => substr($identifier, 0, 190),
                'title' => substr($title, 0, 512),
                'abstract' => '',
                'body' => $body,
                'help_type' => $helpType,
                'parent_identifier' => '',
                'source_path' => (string)$falFile->getPublicUrl(),
                'media' => 0,
            ]);
            $this->repository->attachMedia($falFile, $helpdocUid, $languageId, $pid);
            $imported++;
            $mediaCopied++;

            if ($onProgress !== null) {
                $onProgress($index, $total, $identifier);
            }
        }

        return new ImportResult(
            imported: $imported,
            skipped: $skipped,
            mediaCopied: $mediaCopied,
            message: sprintf('Folder import from "%s" (language %d)', $folder->getCombinedIdentifier(), $languageId),
        );
    }

    /**
     * @return list<FalFile>
     */
    private function collectFiles(Folder $folder, bool $recursive): array
    {
        $files = [];
        foreach ($folder->getFiles() as $file) {
            $files[] = $file;
        }
        if ($recursive) {
            foreach ($folder->getSubfolders() as $sub) {
                foreach ($this->collectFiles($sub, true) as $deep) {
                    $files[] = $deep;
                }
            }
        }
        return $files;
    }
}

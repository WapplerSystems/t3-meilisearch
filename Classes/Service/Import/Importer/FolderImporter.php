<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import\Importer;

use TYPO3\CMS\Core\Resource\File as FalFile;
use TYPO3\CMS\Core\Resource\Folder;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceRepository;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\ImportResult;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;

/**
 * Batch-import every file already present in a FAL folder as one
 * knowledge resource per file. The folder lives wherever the operator chose
 * (default storage's fileadmin, or any other storage they have access
 * to) — sys_file rows for those files may already exist; we re-use
 * them rather than copying.
 *
 * Use cases: an editor uploaded a stack of PDFs into
 * fileadmin/handbooks/ via FileList, or a sync process drops Markdown
 * into fileadmin/imports/ daily. The importer points at that folder
 * and turns each file into a searchable knowledge resource — title from the file
 * name (sans extension), body from Tika extraction.
 *
 * Subfolders are walked iff `recursive` is true. Hidden files (those
 * starting with ".") are skipped. The file's existing FAL identifier
 * is used; no copy happens, no rename — which keeps the operator's
 * mental model intact (the file in fileadmin IS the knowledge resource's media).
 */
final class FolderImporter implements KnowledgeResourceSourceImporter
{
    public function __construct(
        private readonly KnowledgeResourceRepository $repository,
    ) {}

    public function name(): string
    {
        return 'folder';
    }

    public function label(): string
    {
        return 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.folder.label';
    }

    public function description(): string
    {
        return 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.folder.description';
    }

    public function describeFields(): array
    {
        return [
            ['name' => 'folder', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.folder.sourceFolder.label', 'type' => 'folder', 'required' => true,
             'help' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.folder.sourceFolder.help'],
            ['name' => 'recursive', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.folder.recursive.label', 'type' => 'checkbox', 'default' => false,
             'help' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.folder.recursive.help'],
            ['name' => 'language', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.targetLanguage', 'type' => 'language', 'default' => 0],
            ['name' => 'pid', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.storagePid', 'type' => 'text', 'default' => '0',
             'help' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.storagePidHelp'],
            ['name' => 'resource_type', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.documentKind', 'type' => 'select', 'default' => 'reference',
             'options' => ['reference' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.reference', 'concept' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.concept', 'task' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.task', 'upload' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.upload']],
            ['name' => 'titleFromFilename', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.titleFromFilename', 'type' => 'checkbox', 'default' => true,
             'help' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.folder.titleFromFilenameHelp'],
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
        $resourceType = trim((string)($config['resource_type'] ?? 'reference'));
        if (!in_array($resourceType, ['reference', 'concept', 'task', 'upload'], true)) {
            $resourceType = 'reference';
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

            $knowledgeResourceUid = $this->repository->insertKnowledgeResource([
                'pid' => $pid,
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
            $this->repository->attachMedia($falFile, $knowledgeResourceUid, $languageId, $pid);
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

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import\Importer;

use Psr\Http\Message\UploadedFileInterface;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceRepository;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\ImportResult;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;

/**
 * Persists a single editor-uploaded document (PDF / DOCX / HTML / MD /
 * TXT / Office / EPUB — anything covered by
 * meilisearch.tika.allowedMimeTypes) as one knowledge resource row.
 *
 * Expected `$config`:
 *   - 'upload'    => UploadedFileInterface (required)
 *   - 'title'     => string (optional; falls back to file name)
 *   - 'abstract'  => string (optional)
 *   - 'language'  => int (default 0)
 *   - 'resource_type' => string in {upload, concept, task, reference} (default 'upload')
 *
 * Title + abstract are editor-controlled; Tika's text extraction populates
 * `body` so searches still find the content even when the editor doesn't
 * type a summary.
 *
 * Identifier is `<sanitised-filename>-f<sysFileUid>` — two "report.pdf"
 * uploads no longer collide; the FAL uid suffix is stable across renames.
 */
final class SingleFileImporter implements KnowledgeResourceSourceImporter
{
    public function __construct(
        private readonly KnowledgeResourceRepository $repository,
    ) {}

    public function name(): string
    {
        return 'single-file';
    }

    public function label(): string
    {
        return 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.label';
    }

    public function description(): string
    {
        return 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.description';
    }

    public function describeFields(): array
    {
        return [
            ['name' => 'upload', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.file.label', 'type' => 'file', 'required' => true],
            ['name' => 'title', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.title.label', 'type' => 'text',
             'help' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.title.help'],
            ['name' => 'abstract', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.abstract.label', 'type' => 'textarea',
             'help' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.abstract.help'],
            ['name' => 'language', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.language', 'type' => 'language', 'default' => 0],
            ['name' => 'resource_type', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.documentKind', 'type' => 'select', 'default' => 'upload',
             'options' => ['upload' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.upload', 'concept' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.concept', 'task' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.task', 'reference' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.resourceType.reference']],
            ['name' => 'targetFolder', 'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.common.targetFolder', 'type' => 'folder',
             'help' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:be.importer.singleFile.targetFolder.help'],
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
        $title = trim((string)($config['title'] ?? ''));
        if ($title === '') {
            $title = pathinfo($clientFilename, PATHINFO_FILENAME);
        }
        $abstract = trim((string)($config['abstract'] ?? ''));
        $languageId = (int)($config['language'] ?? 0);
        $resourceType = trim((string)($config['resource_type'] ?? 'upload'));
        if (!in_array($resourceType, ['upload', 'concept', 'task', 'reference'], true)) {
            $resourceType = 'upload';
        }

        // Stage the upload to a temp file so FAL's addFile() can copy from
        // disk. Don't use tempnam()+moveTo() — TYPO3's PSR-7 UploadedFile
        // rejects moveTo() targets that already exist, which tempnam does
        // create.
        $tmpPath = sys_get_temp_dir() . '/wsmsupload_' . bin2hex(random_bytes(8));
        $bytes = (string)$upload->getStream()->getContents();
        if ($bytes === '') {
            throw new \RuntimeException('Uploaded file is empty');
        }
        if (file_put_contents($tmpPath, $bytes) === false) {
            throw new \RuntimeException('Cannot stage upload to temp file ' . $tmpPath);
        }

        $targetRoot = trim((string)($config['targetFolder'] ?? ''));

        try {
            $targetName = $this->repository->sanitiseFilename($clientFilename);
            $falFile = $this->repository->addFileToUploads($tmpPath, $targetName, $targetRoot !== '' ? $targetRoot : null);

            $extracted = $this->repository->extractText($falFile);
            $body = $extracted->status === ExtractionResult::SUCCESS ? $extracted->text : '';

            $identifier = $this->repository->sanitiseIdentifier(pathinfo($clientFilename, PATHINFO_FILENAME))
                . '-f' . $falFile->getUid();

            $knowledgeResourceUid = $this->repository->insertKnowledgeResource([
                'pid' => 0,
                'sys_language_uid' => $languageId,
                'identifier' => substr($identifier, 0, 190),
                'title' => substr($title, 0, 512),
                'abstract' => $abstract,
                'body' => $body,
                'resource_type' => $resourceType,
                'parent_identifier' => '',
                // FAL gives us the public URL post-creation; this avoids
                // hardcoding "fileadmin/..." which would break for other
                // storages.
                'source_path' => (string)$falFile->getPublicUrl(),
                'media' => 0,
            ]);
            $this->repository->attachMedia($falFile, $knowledgeResourceUid, $languageId, 0);

            if ($onProgress !== null) {
                $onProgress(1, 1, (string)$identifier);
            }

            return new ImportResult(
                imported: 1,
                skipped: 0,
                mediaCopied: 1,
                message: sprintf('Uploaded "%s" (knowledge_resource #%d, FAL #%d, Tika %s)',
                    $clientFilename,
                    $knowledgeResourceUid,
                    $falFile->getUid(),
                    $extracted->status,
                ),
                extras: [
                    'uid' => $knowledgeResourceUid,
                    'falUid' => $falFile->getUid(),
                    'extractStatus' => $extracted->status,
                    'extractedChars' => mb_strlen($body),
                ],
            );
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
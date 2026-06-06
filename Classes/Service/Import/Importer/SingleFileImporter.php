<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import\Importer;

use Psr\Http\Message\UploadedFileInterface;
use WapplerSystems\Meilisearch\Service\Import\HelpDocRepository;
use WapplerSystems\Meilisearch\Service\Import\HelpDocSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\ImportResult;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;

/**
 * Persists a single editor-uploaded document (PDF / DOCX / HTML / MD /
 * TXT / Office / EPUB — anything covered by
 * meilisearch.tika.allowedMimeTypes) as one helpdoc row.
 *
 * Expected `$config`:
 *   - 'upload'    => UploadedFileInterface (required)
 *   - 'title'     => string (optional; falls back to file name)
 *   - 'abstract'  => string (optional)
 *   - 'language'  => int (default 0)
 *   - 'help_type' => string in {upload, concept, task, reference} (default 'upload')
 *
 * Title + abstract are editor-controlled; Tika's text extraction populates
 * `body` so searches still find the content even when the editor doesn't
 * type a summary.
 *
 * Identifier is `<sanitised-filename>-f<sysFileUid>` — two "report.pdf"
 * uploads no longer collide; the FAL uid suffix is stable across renames.
 */
final class SingleFileImporter implements HelpDocSourceImporter
{
    public function __construct(
        private readonly HelpDocRepository $repository,
    ) {}

    public function name(): string
    {
        return 'single-file';
    }

    public function label(): string
    {
        return 'Single document upload';
    }

    public function description(): string
    {
        return 'One curated document at a time — PDF, DOCX, HTML, Markdown, Office, EPUB, plain text.';
    }

    public function describeFields(): array
    {
        return [
            ['name' => 'upload', 'label' => 'File', 'type' => 'file', 'required' => true],
            ['name' => 'title', 'label' => 'Title', 'type' => 'text',
             'help' => 'Empty = file name. Drives how the entry appears in search results.'],
            ['name' => 'abstract', 'label' => 'Abstract', 'type' => 'textarea',
             'help' => 'Optional one-paragraph summary shown as the snippet in results.'],
            ['name' => 'language', 'label' => 'Language', 'type' => 'language', 'default' => 0],
            ['name' => 'help_type', 'label' => 'Document kind', 'type' => 'select', 'default' => 'upload',
             'options' => ['upload' => 'upload', 'concept' => 'concept', 'task' => 'task', 'reference' => 'reference']],
            ['name' => 'targetFolder', 'label' => 'Target folder', 'type' => 'folder',
             'help' => 'Where the uploaded file lands in fileadmin. Empty = site default (meilisearch.helpdoc.fileadminFolder). The "uploads/" subfolder is added automatically.'],
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
        $helpType = trim((string)($config['help_type'] ?? 'upload'));
        if (!in_array($helpType, ['upload', 'concept', 'task', 'reference'], true)) {
            $helpType = 'upload';
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

            $helpdocUid = $this->repository->insertHelpdoc([
                'pid' => 0,
                'sys_language_uid' => $languageId,
                'identifier' => substr($identifier, 0, 190),
                'title' => substr($title, 0, 512),
                'abstract' => $abstract,
                'body' => $body,
                'help_type' => $helpType,
                'parent_identifier' => '',
                // FAL gives us the public URL post-creation; this avoids
                // hardcoding "fileadmin/..." which would break for other
                // storages.
                'source_path' => (string)$falFile->getPublicUrl(),
                'media' => 0,
            ]);
            $this->repository->attachMedia($falFile, $helpdocUid, $languageId, 0);

            if ($onProgress !== null) {
                $onProgress(1, 1, (string)$identifier);
            }

            return new ImportResult(
                imported: 1,
                skipped: 0,
                mediaCopied: 1,
                message: sprintf('Uploaded "%s" (helpdoc #%d, FAL #%d, Tika %s)',
                    $clientFilename,
                    $helpdocUid,
                    $falFile->getUid(),
                    $extracted->status,
                ),
                extras: [
                    'uid' => $helpdocUid,
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
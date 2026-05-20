<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;
use WapplerSystems\Meilisearch\Service\Tika\TextExtractor;

/**
 * Indexes sys_file rows together with their default-language metadata and
 * Tika-extracted body text. Phase 2 covers PDF / Office / RTF / EPUB /
 * plain text; OCR and per-language metadata land in later phases.
 *
 * Files are not tied to a single site by structure — the same file may be
 * referenced from any number of pages across any number of sites. For
 * simplicity each site gets its own copy of every non-missing file in its
 * index; deduplication by sys_file_reference → site is a Phase 2.1 task.
 */
final class FileSchemaProvider implements SchemaProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly TextExtractor $textExtractor,
    ) {}

    public function getTable(): string
    {
        return 'sys_file';
    }

    public function supports(string $table): bool
    {
        return $table === 'sys_file';
    }

    public function buildDocumentId(int $uid): string
    {
        return 'file-' . $uid;
    }

    public function fetchDocument(int $uid, Site $site): ?array
    {
        try {
            $file = $this->resourceFactory->getFileObject($uid);
        } catch (\Throwable $e) {
            $this->logger?->warning('Cannot load sys_file {uid}: {message}', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
        if ($file->isMissing()) {
            return null;
        }
        return $this->toDocument($file, $site);
    }

    public function iterateDocuments(Site $site): iterable
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $result = $qb->select('uid')
            ->from('sys_file')
            ->where($qb->expr()->eq('missing', 0))
            ->executeQuery();

        while ($row = $result->fetchAssociative()) {
            try {
                $file = $this->resourceFactory->getFileObject((int)$row['uid']);
            } catch (\Throwable) {
                continue;
            }
            $document = $this->toDocument($file, $site);
            if ($document !== null) {
                yield $document;
            }
        }
    }

    public function getAdditionalFields(): array
    {
        return [
            new TextField('mimeType', searchable: false, filterable: true, facet: true),
            new TextField('extension', searchable: false, filterable: true),
            new IntegerField('fileSize', filterable: true, sortable: true),
            new TextField('publicUrl', searchable: false),
            // `bodytext` already declared by NewsSchemaProvider; the factory
            // dedupes by name, so adding it here costs nothing and keeps the
            // Files schema self-describing.
            new TextField('bodytext', searchable: true),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function toDocument(\TYPO3\CMS\Core\Resource\File $file, Site $site): ?array
    {
        $metadata = $file->getMetaData()->get();
        $title = (string)($metadata['title'] ?? '') !== ''
            ? (string)$metadata['title']
            : (string)$file->getName();

        $bodytext = '';
        $result = $this->textExtractor->extract($file, $site);
        if ($result->status === ExtractionResult::SUCCESS) {
            $bodytext = $result->text;
        }

        // getPublicUrl() returns null for private storages (e.g. internal /
        // protected fileadmin folders, or S3 buckets with no public ACL).
        // We index the empty string in that case so the schema stays
        // consistent, and the template falls back to a non-link title.
        $publicUrl = $file->getPublicUrl() ?? '';

        return [
            'id' => $this->buildDocumentId((int)$file->getUid()),
            'type' => 'file',
            'uid' => (int)$file->getUid(),
            'pid' => 0,
            'language' => (int)($metadata['sys_language_uid'] ?? 0),
            'title' => $title,
            'description' => (string)($metadata['description'] ?? ''),
            'keywords' => (string)($metadata['keywords'] ?? ''),
            'bodytext' => $bodytext,
            'mimeType' => (string)$file->getMimeType(),
            'extension' => (string)$file->getExtension(),
            'fileSize' => (int)$file->getSize(),
            'publicUrl' => $publicUrl,
        ];
    }
}

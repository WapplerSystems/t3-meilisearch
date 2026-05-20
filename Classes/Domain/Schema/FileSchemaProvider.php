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
 * Indexes sys_file rows together with their sys_file_metadata, one document
 * per (file, site language) pair. The Tika-extracted body text is the same
 * across languages — only the editorial metadata (title, description,
 * keywords) is overlaid per language.
 *
 * Document ids:
 *   - language 0: `file-{uid}`  (backward compatible with the pre-multi-lang format)
 *   - language X: `file-{uid}-l{X}`
 *
 * The id collision between language 0 and the legacy single-doc format is
 * intentional: an existing index keeps the same canonical id; new translation
 * variants get added alongside on the next reindex.
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
        return $this->buildDocumentIdForLanguage($uid, 0);
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        foreach ($site->getLanguages() as $language) {
            yield $this->buildDocumentIdForLanguage($uid, $language->getLanguageId());
        }
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        try {
            $file = $this->resourceFactory->getFileObject($uid);
        } catch (\Throwable $e) {
            $this->logger?->warning('Cannot load sys_file {uid}: {message}', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
            return;
        }
        if ($file->isMissing()) {
            return;
        }

        // Body text extracts once per file — same content across languages.
        $bodytext = $this->extractBody($file, $site);
        $publicUrl = $file->getPublicUrl() ?? '';

        foreach ($site->getLanguages() as $language) {
            $document = $this->toDocument($file, $language->getLanguageId(), $bodytext, $publicUrl);
            if ($document !== null) {
                yield $document;
            }
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        $languages = $site->getLanguages();

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
            if ($file->isMissing()) {
                continue;
            }

            $bodytext = $this->extractBody($file, $site);
            $publicUrl = $file->getPublicUrl() ?? '';

            foreach ($languages as $language) {
                $document = $this->toDocument($file, $language->getLanguageId(), $bodytext, $publicUrl);
                if ($document !== null) {
                    yield $document;
                }
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

    private function buildDocumentIdForLanguage(int $fileUid, int $languageId): string
    {
        return $languageId === 0
            ? 'file-' . $fileUid
            : 'file-' . $fileUid . '-l' . $languageId;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function toDocument(
        \TYPO3\CMS\Core\Resource\File $file,
        int $languageId,
        string $bodytext,
        string $publicUrl,
    ): ?array {
        $metadata = $this->metadataForLanguage((int)$file->getUid(), $languageId);
        // No default-language record either — sys_file with zero metadata
        // shouldn't normally happen (FAL writes a stub on file creation),
        // but guard against the case where the file rows exist while
        // metadata is missing.
        if ($metadata === []) {
            return null;
        }

        $title = (string)($metadata['title'] ?? '') !== ''
            ? (string)$metadata['title']
            : (string)$file->getName();

        return [
            'id' => $this->buildDocumentIdForLanguage((int)$file->getUid(), $languageId),
            'type' => 'file',
            'uid' => (int)$file->getUid(),
            'pid' => 0,
            'language' => $languageId,
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

    /**
     * Overlay semantics: try the requested language first, then fall back
     * to the default-language row. Mirrors TYPO3's translation overlay for
     * sys_file_metadata without going through PageRepository.
     *
     * @return array<string,mixed>
     */
    private function metadataForLanguage(int $fileUid, int $languageId): array
    {
        if ($languageId > 0) {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
            $row = $qb->select('title', 'description', 'keywords', 'alternative')
                ->from('sys_file_metadata')
                ->where(
                    $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER)),
                )
                ->executeQuery()
                ->fetchAssociative();
            if ($row !== false) {
                return $row;
            }
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $row = $qb->select('title', 'description', 'keywords', 'alternative')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', 0),
            )
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? [] : $row;
    }

    private function extractBody(\TYPO3\CMS\Core\Resource\File $file, Site $site): string
    {
        $result = $this->textExtractor->extract($file, $site);
        return $result->status === ExtractionResult::SUCCESS ? $result->text : '';
    }
}

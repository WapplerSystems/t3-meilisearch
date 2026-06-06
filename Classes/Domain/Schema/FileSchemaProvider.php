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
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\BoostCalculator;
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
 * referenced from any number of pages across any number of sites. Two
 * modes are supported, switched by the per-site `meilisearch.deduplicateFiles`
 * setting:
 *  - false (default): index every non-missing sys_file into every site's
 *    index. Useful when the search index doubles as a global file library.
 *  - true: only index files that are referenced (via sys_file_reference)
 *    from a page belonging to the current site. Strict per-site results,
 *    no cross-site leakage.
 */
final class FileSchemaProvider implements SchemaProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Lazily-built [siteIdentifier => [fileUid => true]] map of which files
     * are referenced on which sites. Memoized on the provider instance so a
     * full reindex pays the underlying sys_file_reference query only once.
     *
     * @var array<string,array<int,bool>>|null
     */
    private ?array $filesPerSite = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly TextExtractor $textExtractor,
        private readonly SiteFinder $siteFinder,
        private readonly BoostCalculator $boostCalculator,
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
        if (!$this->isFileEligibleForSite($uid, $site)) {
            return;
        }

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
            $document = $this->toDocument($file, $language->getLanguageId(), $bodytext, $publicUrl, $site);
            if ($document !== null) {
                yield $document;
            }
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        $languages = $site->getLanguages();
        $dedupe = $this->shouldDeduplicate($site);
        $eligibleUids = $dedupe ? $this->filesForSite($site) : null;
        $excludeExtensions = $this->excludeExtensions($site);
        $minImageBytes = $this->minImageBytes($site);

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $result = $qb->select('uid')
            ->from('sys_file')
            ->where($qb->expr()->eq('missing', 0))
            ->executeQuery();

        while ($row = $result->fetchAssociative()) {
            $fileUid = (int)$row['uid'];
            if ($eligibleUids !== null && !isset($eligibleUids[$fileUid])) {
                continue;
            }
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (\Throwable) {
                continue;
            }
            if ($file->isMissing()) {
                continue;
            }
            // Drop config / log / backup junk before doing any FAL or
            // Tika work — keeps re-indexes fast and stops the search
            // corpus from accumulating files no visitor will ever look
            // for. Comparison is lowercase since site setting + FAL
            // extension are normalised the same way.
            if ($excludeExtensions !== [] && in_array(strtolower((string)$file->getExtension()), $excludeExtensions, true)) {
                continue;
            }
            // Tiny icons / flags / decoration pollute the corpus
            // without being useful as results — skip any image
            // under the operator-chosen byte threshold. Checking
            // the mime prefix scopes the filter so legitimate
            // small text files (a 200-byte README) still index.
            if ($minImageBytes > 0
                && (int)$file->getSize() < $minImageBytes
                && str_starts_with((string)$file->getMimeType(), 'image/')
            ) {
                continue;
            }

            // The full document build calls into FAL for mime / size /
            // public URL, which probes the underlying storage. On environments
            // forked from prod (S3 storage missing locally, files deleted out-
            // of-band, broken sys_file rows with missing=0) this can throw —
            // skip the file with a warning rather than failing the whole
            // reindex. The DB `missing=0` filter at the SQL level catches the
            // common case; this guard handles the rest.
            try {
                $bodytext = $this->extractBody($file, $site);
                $publicUrl = $file->getPublicUrl() ?? '';
            } catch (\Throwable $e) {
                $this->logger?->warning('FileSchemaProvider skipped {uid} ({ident}): cannot read file: {message}', [
                    'uid' => $file->getUid(),
                    'ident' => $file->getIdentifier(),
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($languages as $language) {
                try {
                    $document = $this->toDocument($file, $language->getLanguageId(), $bodytext, $publicUrl, $site);
                } catch (\Throwable $e) {
                    $this->logger?->warning('FileSchemaProvider skipped {uid} for language {lang}: {message}', [
                        'uid' => $file->getUid(),
                        'lang' => $language->getLanguageId(),
                        'message' => $e->getMessage(),
                    ]);
                    continue;
                }
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
        Site $site,
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
            'content' => $bodytext,
            'uri' => $publicUrl,
            'mimeType' => (string)$file->getMimeType(),
            'extension' => (string)$file->getExtension(),
            'fileSize' => (int)$file->getSize(),
            'publicUrl' => $publicUrl,
            // sys_file has no editor-curated boost TCA — type-level only.
            'boost' => $this->boostCalculator->compositeFor($site, 'file', null),
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

    /**
     * Drop the lazy filesPerSite map so the next read query
     * sys_file_reference fresh. Called by the DataHandler hook after a
     * sys_file_reference row changed — without this, a same-request
     * reindex would still see the pre-edit membership.
     */
    public function clearMembershipCache(): void
    {
        $this->filesPerSite = null;
    }

    private function shouldDeduplicate(Site $site): bool
    {
        return (bool)$site->getSettings()->get('meilisearch.deduplicateFiles', false);
    }

    /**
     * @return list<string> lower-case file extensions that should be skipped
     */
    private function excludeExtensions(Site $site): array
    {
        $raw = $site->getSettings()->get('meilisearch.indexing.excludeExtensions', []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $ext) {
            $ext = ltrim(strtolower((string)$ext), '.');
            if ($ext !== '') {
                $out[] = $ext;
            }
        }
        return $out;
    }

    /**
     * Returns the minimum image size in bytes (operator configures the
     * value in KB). 0 means the filter is disabled.
     */
    private function minImageBytes(Site $site): int
    {
        $kb = (int)$site->getSettings()->get('meilisearch.indexing.minImageSizeKb', 0);
        return max(0, $kb) * 1024;
    }

    /**
     * For dedup mode: is this single fileUid referenced from any page that
     * resolves to the given site? Caller in fetchDocuments uses this; the
     * full-rebuild path in iterateDocuments uses filesForSite() instead so
     * we don't repeat the underlying join per row.
     */
    private function isFileEligibleForSite(int $fileUid, Site $site): bool
    {
        if (!$this->shouldDeduplicate($site)) {
            return true;
        }
        return isset($this->filesForSite($site)[$fileUid]);
    }

    /**
     * @return array<int,bool> [fileUid => true] for files referenced on this site
     */
    private function filesForSite(Site $site): array
    {
        if ($this->filesPerSite === null) {
            $this->filesPerSite = $this->buildFilesPerSiteMap();
        }
        return $this->filesPerSite[$site->getIdentifier()] ?? [];
    }

    /**
     * One round-trip to sys_file_reference for the whole map. References
     * with pid=0 (e.g. files attached to be_users avatars) or pids that
     * don't resolve to any site are skipped — they aren't part of any
     * frontend search experience.
     *
     * @return array<string,array<int,bool>>
     */
    private function buildFilesPerSiteMap(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $rows = $qb->select('uid_local', 'pid')
            ->distinct()
            ->from('sys_file_reference')
            ->where($qb->expr()->gt('pid', 0))
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            try {
                $site = $this->siteFinder->getSiteByPageId((int)$row['pid']);
            } catch (\Throwable) {
                continue;
            }
            $map[$site->getIdentifier()][(int)$row['uid_local']] = true;
        }
        return $map;
    }
}

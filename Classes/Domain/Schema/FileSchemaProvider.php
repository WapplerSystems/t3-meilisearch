<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use Meilisearch\Client;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
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
final class FileSchemaProvider implements SchemaProviderInterface, PreReindexCleanupInterface, LoggerAwareInterface
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
        private readonly \WapplerSystems\Meilisearch\Service\LanguageDetector $languageDetector,
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
        if ($this->identifierIsExcluded((string)$file->getIdentifier(), $this->excludeIdentifierPrefixes($site))) {
            return;
        }

        // URL + metadata first (cheap, can't really fail). Body
        // extraction is best-effort — see the long comment in
        // iterateDocuments() for the rationale.
        $publicUrl = $this->normalisePublicUrl((string)$file->getPublicUrl());
        try {
            $bodytext = $this->extractBody($file, $site);
        } catch (\Throwable $e) {
            $this->logger?->info('FileSchemaProvider body extraction skipped for {uid}: {message}', [
                'uid' => $file->getUid(),
                'message' => $e->getMessage(),
            ]);
            $bodytext = '';
        }

        foreach ($site->getLanguages() as $language) {
            $document = $this->toDocument($file, $language->getLanguageId(), $bodytext, $publicUrl, $site);
            if ($document !== null) {
                yield $document;
            }
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        // Emergency bypass for environments where the FAL storage backend
        // returns NoSuchKey / 404 for many sys_file rows (typical after a
        // bucket rename, a fal_s3 storage swap, or a partial migration).
        // Each broken row triggers AWS-SDK retry-with-backoff and the
        // reindex spends hours probing dead keys before News / KR / Pages
        // get a chance. Operator can set this flag, run the reindex to
        // populate the other corpora, then clean up sys_file and turn the
        // flag back off. Pre-reindex cleanup hook still runs — it only
        // touches Meilisearch-side orphan docs, not sys_file.
        if ((bool)$site->getSettings()->get('meilisearch.indexing.skipFalForBrokenStorage', false) === true) {
            $this->logger?->info(
                'FileSchemaProvider bypassed for site {id} (skipFalForBrokenStorage=true)',
                ['id' => $site->getIdentifier()],
            );
            return;
        }

        $languages = $site->getLanguages();
        $dedupe = $this->shouldDeduplicate($site);
        $eligibleUids = $dedupe ? $this->filesForSite($site) : null;
        // Whitelist wins when non-empty (only these extensions index);
        // otherwise fall back to blacklist (everything except these).
        $allowedExtensions = $this->normaliseExtensionList($site, 'meilisearch.indexing.allowedExtensions');
        $excludeExtensions = $allowedExtensions === []
            ? $this->normaliseExtensionList($site, 'meilisearch.indexing.excludeExtensions')
            : [];
        $minImageBytes = $this->minImageBytes($site);
        $excludePrefixes = $this->excludeIdentifierPrefixes($site);

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->select('uid')
            ->from('sys_file')
            ->where($qb->expr()->eq('missing', 0));
        // Filter generated artefacts (typo3temp chart PNGs, _processed_
        // derivatives, …) at SQL level so we don't pay the FAL object
        // hydration for rows we're about to drop. NOT LIKE per prefix
        // — Doctrine has no `notStartsWith`, but the leading literal
        // makes an index scan cheap regardless.
        foreach ($excludePrefixes as $prefix) {
            $param = $qb->createNamedParameter($this->escapeLikePrefix($prefix) . '%');
            $qb->andWhere($qb->expr()->notLike('identifier', $param));
        }
        $result = $qb->executeQuery();

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
            // Extension filter: whitelist wins (only listed extensions
            // pass) when non-empty, otherwise blacklist (skip listed
            // extensions). Both lists are lowercase since site setting
            // + FAL extension normalise the same way. Keep this before
            // the FAL probes below — bad sys_file rows shouldn't even
            // make it to the size check.
            $ext = strtolower((string)$file->getExtension());
            if ($allowedExtensions !== [] && !in_array($ext, $allowedExtensions, true)) {
                continue;
            }
            if ($excludeExtensions !== [] && in_array($ext, $excludeExtensions, true)) {
                continue;
            }
            // The full document build calls into FAL for mime / size /
            // public URL. Metadata calls (getSize / getMimeType /
            // getPublicUrl) come from the sys_file row + storage config
            // and stay cheap. Body extraction by contrast reads the
            // actual bytes through Tika — on environments forked from
            // prod where files don't exist locally yet, this is the
            // call that throws. Order the work so URL + metadata land
            // first; body becomes optional. The DB `missing=0` filter
            // at the SQL level catches the obvious case; this guard
            // handles broken sys_file rows where missing=0 but the
            // file isn't on the storage anyway.
            try {
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
                $publicUrl = $this->normalisePublicUrl((string)$file->getPublicUrl());
            } catch (\Throwable $e) {
                $this->logger?->warning('FileSchemaProvider skipped {uid} ({ident}): cannot read metadata: {message}', [
                    'uid' => $file->getUid(),
                    'ident' => $file->getIdentifier(),
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
            // Body extraction is best-effort. A failure here (Tika
            // unreachable, file content missing on a forked
            // environment, OCR timeout) leaves the doc indexable as
            // title + metadata only — the operator can still find
            // it by name without losing the entry entirely.
            try {
                $bodytext = $this->extractBody($file, $site);
            } catch (\Throwable $e) {
                $this->logger?->info('FileSchemaProvider body extraction skipped for {uid}: {message}', [
                    'uid' => $file->getUid(),
                    'message' => $e->getMessage(),
                ]);
                $bodytext = '';
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
            // Cross-type FE-access-control field. Holds the per-doc allowed
            // fe_group ids (TYPO3 convention: empty = public; -2 = any logged-in
            // user; -1 = only anonymous; positive = that specific group). The
            // AccessControlFilter service consumes this at search time to
            // hide restricted docs from visitors who don't carry a matching
            // group id. Declared here once; the merged schema applies to all
            // doc types.
            new IntegerField('accessGroups', multiple: true, searchable: false, filterable: true),
            // Index-time generated preview thumbnail URL for image files
            // (empty for non-images / processing failures). Rendered in the
            // result list; not searchable.
            new TextField('thumbnailUrl', searchable: false),
            // Dedicated, unique, fixed-width search token per image
            // (e.g. "img0000001426"). The suggest dropdown navigates to the
            // results page with q=<searchToken>; because the token is unique,
            // fixed-width (no prefix collisions) and excluded from typo
            // tolerance (meilisearch.defaults.typoTolerance.disableOnAttributes),
            // that query narrows down to exactly this one image. Searchable.
            new TextField('searchToken', searchable: true),
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

        // Image-only: a preview thumbnail + a unique fixed-width search
        // token so the suggest dropdown can "search this one image".
        $isImage = str_starts_with((string)$file->getMimeType(), 'image/');
        // Per-(uid, language) so q=<token> resolves to exactly ONE document
        // even though a file is indexed once per language overlay (all of
        // which would otherwise share the same token). Fixed width + typo
        // tolerance disabled on `searchToken` ⇒ no prefix/typo collisions.
        $searchToken = $isImage ? sprintf('img%010dl%03d', (int)$file->getUid(), $languageId) : '';
        $thumbnailUrl = $isImage ? $this->resolveThumbnailUrl($file, $site) : '';

        return [
            'id' => $this->buildDocumentIdForLanguage((int)$file->getUid(), $languageId),
            'type' => 'file',
            'thumbnailUrl' => $thumbnailUrl,
            'searchToken' => $searchToken,
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
            // FAL access control: sys_file_metadata.fe_groups (longtext,
            // CSV of group ids) — empty/null → public, otherwise the
            // visitor must carry one of those ids. Per-reference fe_group
            // (sys_file_reference) is NOT honoured here because a single
            // sys_file can be referenced from many records with differing
            // restrictions; that lookup would need a separate index path.
            'accessGroups' => self::parseFeGroups((string)($metadata['fe_groups'] ?? '')),
            // sys_file has no editor-curated boost TCA — type-level only.
            'boost' => $this->boostCalculator->compositeFor($site, 'file', null),
            // Detected content language (ISO 639-1). A file gets indexed
            // under every site-language overlay with identical body bytes,
            // so the only signal of "what language is this PDF actually
            // written in?" is the extracted text itself.
            'contentLanguage' => $this->languageDetector->detect($site, $bodytext),
        ];
    }

    /**
     * Parse a TYPO3 fe_group(s) CSV into an int[]; treats empty / '0' as
     * "no restriction" and drops 0 entries so the empty-array semantics
     * match across all doc types.
     *
     * @return list<int>
     */
    private static function parseFeGroups(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return [];
        }
        $ids = array_map(
            static fn (string $g): int => (int) trim($g),
            explode(',', $raw),
        );
        return array_values(array_filter($ids, static fn (int $g): bool => $g !== 0));
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
            $row = $qb->select('title', 'description', 'keywords', 'alternative', 'fe_groups')
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
        $row = $qb->select('title', 'description', 'keywords', 'alternative', 'fe_groups')
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
     * Read a stringlist site setting holding file extensions and
     * normalise it: lowercase, strip leading dots, drop empties.
     * Used for both the allowed-extensions whitelist and the
     * excluded-extensions blacklist.
     *
     * @return list<string>
     */
    private function normaliseExtensionList(Site $site, string $settingKey): array
    {
        $raw = $site->getSettings()->get($settingKey, []);
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
     * Index-time preview thumbnail for an image file, scaled to fit within
     * the configured max dimensions (no crop). Returns '' for non-resolvable
     * or failed processing (e.g. remote fal_s3 hiccups) — the result list
     * then falls back to the plain file rendering.
     */
    private function resolveThumbnailUrl(File $file, Site $site): string
    {
        $maxW = (int)$site->getSettings()->get('meilisearch.files.thumbnail.maxWidth', 320);
        $maxH = (int)$site->getSettings()->get('meilisearch.files.thumbnail.maxHeight', 320);
        if ($maxW <= 0 && $maxH <= 0) {
            return '';
        }
        $config = [];
        if ($maxW > 0) {
            $config['maxWidth'] = $maxW;
        }
        if ($maxH > 0) {
            $config['maxHeight'] = $maxH;
        }
        try {
            $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $config);
            $url = (string)$processed->getPublicUrl();
            return $url !== '' ? $this->normalisePublicUrl($url) : '';
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Thumbnail generation failed for sys_file {uid}: {msg}',
                ['uid' => $file->getUid(), 'msg' => $e->getMessage()],
            );
            return '';
        }
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
     * Read the list of sys_file identifier prefixes that should be kept
     * out of the index. Used to drop generated artefacts (typo3temp
     * charts, _processed_ derivatives) that surface as PNG/JPG search
     * hits without ever being useful results.
     *
     * @return list<string>
     */
    private function excludeIdentifierPrefixes(Site $site): array
    {
        $raw = $site->getSettings()->get('meilisearch.indexing.excludeIdentifierPrefixes', []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $prefix) {
            $prefix = (string)$prefix;
            if ($prefix !== '') {
                $out[] = $prefix;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $prefixes
     */
    private function identifierIsExcluded(string $identifier, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($identifier, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Escape LIKE wildcards in a literal prefix so user-supplied paths
     * containing `_` or `%` (legal in filesystem names) don't broaden
     * the NOT LIKE filter. Backslash-escape works on both MySQL and
     * MariaDB with the default LIKE escape char.
     */
    private function escapeLikePrefix(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
    }

    /**
     * FAL's `getPublicUrl()` returns relative paths like
     * "fileadmin/foo.pdf" or "typo3conf/ext/.../bar.png" without a
     * leading slash. Frontend result cards embed the URL as `<a href>`
     * — a missing slash makes the browser resolve relative to the
     * current page (e.g. `/de/suche/fileadmin/foo.pdf` → 404). This
     * normalises by adding a slash to bare paths and leaving absolute
     * URLs / protocol-relative URLs alone.
     */
    private function normalisePublicUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/')
            || str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
            || str_starts_with($url, '//')
        ) {
            return $url;
        }
        return '/' . $url;
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

    /**
     * Pre-reindex eviction of file documents that no longer pass the
     * operator's current filters. Without this, docs that USED to be
     * eligible stay orphaned in the index forever: iterateDocuments only
     * yields the new eligible set, so it can never reach a document that
     * has dropped out of it.
     *
     * Every filter the indexer applies at write time needs a counterpart
     * here, otherwise tightening a setting has no effect on what is
     * already indexed:
     *
     *   excludeIdentifierPrefixes → `uri CONTAINS "<prefix>"`
     *   allowedExtensions         → `extension NOT IN [...]`
     *   excludeExtensions         → `extension IN [...]`
     *   minImageSizeKb            → `mimeType STARTS WITH "image/" AND fileSize < N`
     *
     * The extension and size rules were missing until 2026-08-24, which
     * is why the LINEAR index still carried 96 documents — .exe
     * installers, .form.yaml definitions and sub-10 KB logos — more than
     * two months after the switch from a blacklist to a whitelist had
     * made them ineligible.
     *
     * Each rule becomes one delete-task. `CONTAINS` is an experimental
     * filter operator — Meilisearch answers `Using `CONTAINS` requires
     * enabling the `contains filter` experimental feature` when the flag
     * is off. Failures are logged per rule and never fail the reindex.
     */
    public function cleanupBeforeReindex(Site $site, Client $client, string $indexName): int
    {
        $filters = $this->buildCleanupFilters($site);
        if ($filters === []) {
            return 0;
        }
        // Skip on a fresh / empty index: a just-recreated index hasn't had
        // its filterableAttributes pushed yet (apply-settings runs AFTER
        // the index exists), so `uri CONTAINS "…"` would fail with
        // "Attribute `uri` is not filterable" — and every subsequent
        // doc-add task in the same batch inherits that failure. Empty
        // index has no orphans anyway, so the skip is also semantically
        // correct.
        try {
            $stats = $client->index($indexName)->stats();
            if ((int)($stats['numberOfDocuments'] ?? 0) === 0) {
                return 0;
            }
        } catch (\Throwable) {
            // Index doesn't exist yet → no orphans to clean up.
            return 0;
        }
        $total = 0;
        foreach ($filters as $label => $filter) {
            try {
                $task = $client->index($indexName)->deleteDocuments(['filter' => $filter]);
                // Wait briefly so the operator sees the actual count in
                // the reindex output; pre-reindex evictions are bounded
                // (a few thousand at most), so blocking up to ~30s is
                // acceptable here even with a big index.
                if (method_exists($client, 'waitForTask')) {
                    $result = $client->waitForTask($task['taskUid'] ?? null, timeoutInMs: 30000);
                    $deleted = (int)($result['details']['deletedDocuments'] ?? 0);
                } else {
                    // Older SDK shape — fire-and-forget.
                    $deleted = 0;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning(
                    'FileSchemaProvider pre-reindex cleanup failed for rule {rule}: {message}',
                    ['rule' => $label, 'message' => $e->getMessage()],
                );
                continue;
            }
            if ($deleted > 0) {
                $this->logger?->info(
                    'FileSchemaProvider evicted {count} documents no longer matching {rule}',
                    ['count' => $deleted, 'rule' => $label],
                );
            }
            $total += $deleted;
        }
        return $total;
    }

    /**
     * One Meilisearch filter expression per active eviction rule, keyed
     * by a human-readable label for the log.
     *
     * `extension EXISTS` / `fileSize EXISTS` guard every rule that reads
     * a field the EXT:index crawl bridge does not write: without it, a
     * `NOT IN` matches documents that simply lack the attribute, and the
     * cleanup would delete every crawl-indexed file document.
     *
     * @return array<string,string>
     */
    private function buildCleanupFilters(Site $site): array
    {
        $filters = [];

        foreach ($this->excludeIdentifierPrefixes($site) as $prefix) {
            // Escape embedded double quotes in the literal (FAL paths
            // generally don't contain them, but the filter syntax has to
            // be defensive). Backslash-escape works in Meilisearch's
            // expression grammar.
            // `type = file` is not decoration: the prefixes describe FAL
            // identifiers, but the filter runs against `uri`, and other
            // document types can carry a URI containing the same
            // fragment. A help document served from
            // /hilfe/fileadmin/chatbot-hilfe/uploads/… was being deleted
            // by the `/uploads/` rule on every single reindex, then
            // re-created by its own provider moments later.
            $filters['identifier prefix ' . $prefix] = sprintf(
                'type = file AND uri CONTAINS %s',
                $this->quoteFilterValue($prefix),
            );
        }

        // Whitelist wins when non-empty, mirroring iterateDocuments().
        $allowed = $this->normaliseExtensionList($site, 'meilisearch.indexing.allowedExtensions');
        if ($allowed !== []) {
            $filters['allowedExtensions'] = sprintf(
                'type = file AND extension EXISTS AND extension NOT IN [%s]',
                implode(', ', array_map($this->quoteFilterValue(...), $allowed)),
            );
        } else {
            $excluded = $this->normaliseExtensionList($site, 'meilisearch.indexing.excludeExtensions');
            if ($excluded !== []) {
                $filters['excludeExtensions'] = sprintf(
                    'type = file AND extension IN [%s]',
                    implode(', ', array_map($this->quoteFilterValue(...), $excluded)),
                );
            }
        }

        $minImageBytes = $this->minImageBytes($site);
        if ($minImageBytes > 0) {
            // Same scoping as the write path: only images are subject to
            // the size floor, so a 200-byte README keeps its document.
            $filters['minImageSizeKb'] = sprintf(
                'type = file AND fileSize EXISTS AND mimeType STARTS WITH "image/" AND fileSize < %d',
                $minImageBytes,
            );
        }

        return $filters;
    }

    private function quoteFilterValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import\Importer;

use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceRepository;
use WapplerSystems\Meilisearch\Service\Import\KnowledgeResourceSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\ImportResult;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;

/**
 * Fetch a list of URLs over HTTP and turn each into a knowledge resource. The
 * downloaded payload lands in FAL (so search results can deep-link
 * to the original file), Tika extracts the body, and the URL itself
 * becomes the source_path.
 *
 * Use cases: import an existing knowledge base hosted on Confluence /
 * S3 / a public docs site, seed search from a CSV of PDF links from
 * legal / HR, suck in a list of internal wiki pages.
 *
 * Safety notes:
 *   - SSRF: only http/https schemes are accepted. We do NOT enforce a
 *     domain allowlist — operators with BE access are trusted, but
 *     don't expose this to anonymous users.
 *   - Download size is capped via the maxSizeMb field; oversize
 *     responses abort cleanly. Default 50 MB matches typical PDF.
 *   - Per-URL timeout caps any single fetch so one slow server can't
 *     wedge the whole import.
 *
 * Input format: one URL per line. Blank lines and lines starting with
 * `#` are skipped (comments).
 */
final class UrlListImporter implements KnowledgeResourceSourceImporter
{
    /** Subfolder under the chosen target where downloads land. */
    private const URLS_SUBFOLDER = 'urls';

    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_MAX_SIZE_MB = 50;

    /**
     * Content-Type → file extension map for URLs that don't carry an
     * extension in their path (e.g. /api/document/42). Anything not
     * in here falls back to .bin which makes Tika skip the entry —
     * search by URL still works via source_path.
     */
    private const MIME_TO_EXT = [
        'text/html' => 'html',
        'application/xhtml+xml' => 'xml',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/markdown' => 'md',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/epub+zip' => 'epub',
        'application/rtf' => 'rtf',
    ];

    public function __construct(
        private readonly KnowledgeResourceRepository $repository,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function name(): string
    {
        return 'url-list';
    }

    public function label(): string
    {
        return 'URL list (HTTP fetch)';
    }

    public function description(): string
    {
        return 'Fetch a list of URLs and create one knowledge resource per response — body via Tika, source kept in FAL.';
    }

    public function describeFields(): array
    {
        return [
            ['name' => 'urls', 'label' => 'URLs', 'type' => 'textarea', 'required' => true,
             'help' => 'One URL per line. Only http/https. Blank lines and lines starting with # are skipped.'],
            ['name' => 'language', 'label' => 'Target sys_language_uid', 'type' => 'language', 'default' => 0],
            ['name' => 'resource_type', 'label' => 'Document kind', 'type' => 'select', 'default' => 'reference',
             'options' => ['reference' => 'reference', 'concept' => 'concept', 'task' => 'task', 'upload' => 'upload']],
            ['name' => 'targetFolder', 'label' => 'Target folder', 'type' => 'folder',
             'help' => 'Where downloaded files land in fileadmin. Empty = site default (meilisearch.knowledgeResource.fileadminFolder). A "urls/" subfolder is added automatically.'],
            ['name' => 'timeout', 'label' => 'HTTP timeout (s)', 'type' => 'text', 'default' => (string)self::DEFAULT_TIMEOUT,
             'help' => 'Per-URL fetch timeout. Slow servers fail individually without wedging the whole batch.'],
            ['name' => 'maxSizeMb', 'label' => 'Max response size (MB)', 'type' => 'text', 'default' => (string)self::DEFAULT_MAX_SIZE_MB,
             'help' => 'Responses larger than this are aborted and counted as skipped.'],
        ];
    }

    public function import(array $config, ?callable $onProgress = null): ImportResult
    {
        $raw = (string)($config['urls'] ?? '');
        $urls = $this->parseUrls($raw);
        if ($urls === []) {
            throw new \RuntimeException('No URLs to import (after stripping blanks and comments).');
        }
        $languageId = (int)($config['language'] ?? 0);
        $resourceType = trim((string)($config['resource_type'] ?? 'reference'));
        if (!in_array($resourceType, ['reference', 'concept', 'task', 'upload'], true)) {
            $resourceType = 'reference';
        }
        $targetRoot = trim((string)($config['targetFolder'] ?? ''));
        $timeout = max(1, (int)($config['timeout'] ?? self::DEFAULT_TIMEOUT));
        $maxBytes = max(1, (int)($config['maxSizeMb'] ?? self::DEFAULT_MAX_SIZE_MB)) * 1024 * 1024;

        // Resolve the FAL target (auto-creating urls/ subfolder) once.
        $rootIdentifier = $targetRoot !== ''
            ? rtrim($targetRoot, '/') . '/' . self::URLS_SUBFOLDER . '/'
            : $this->repository->getDefaultTargetFolder()->getCombinedIdentifier() . self::URLS_SUBFOLDER . '/';
        $targetFolder = $this->repository->resolveOrCreateFolder($rootIdentifier);

        $total = count($urls);
        $imported = 0;
        $skipped = 0;
        $mediaCopied = 0;
        $errors = [];

        foreach ($urls as $index => $url) {
            try {
                $download = $this->fetchUrl($url, $timeout, $maxBytes);
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = sprintf('%s — %s', $url, $e->getMessage());
                if ($onProgress !== null) {
                    $onProgress($index + 1, $total, $url);
                }
                continue;
            }

            // Stage to a tmp file so FAL's addFile() can copy it.
            $tmpPath = sys_get_temp_dir() . '/wsmurl_' . bin2hex(random_bytes(8)) . '.' . $download['ext'];
            if (file_put_contents($tmpPath, $download['body']) === false) {
                $skipped++;
                $errors[] = sprintf('%s — cannot stage temp file', $url);
                continue;
            }

            try {
                $falFile = $this->repository->addFileToFolder(
                    $tmpPath,
                    $targetFolder,
                    $this->repository->sanitiseFilename($download['filename']),
                    true, // removeOriginal: temp file no longer needed
                );

                $extracted = $this->repository->extractText($falFile);
                $body = $extracted->status === ExtractionResult::SUCCESS ? $extracted->text : '';
                // When Tika returned nothing (mime not in its
                // allowlist, e.g. text/html or text/xml), pull plain
                // text from the markup directly so the entry stays
                // searchable.
                if ($body === '' && (str_starts_with($download['mime'], 'text/') || $download['mime'] === 'application/xhtml+xml')) {
                    $body = $this->htmlToText($download['body']);
                }

                $title = $download['title'] !== '' ? $download['title'] : $download['filename'];
                $identifier = $this->repository->sanitiseIdentifier(pathinfo($download['filename'], PATHINFO_FILENAME))
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
                    'source_path' => $url, // Original URL is the canonical source
                    'media' => 0,
                ]);
                $this->repository->attachMedia($falFile, $knowledgeResourceUid, $languageId, 0);
                $imported++;
                $mediaCopied++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = sprintf('%s — %s', $url, $e->getMessage());
                if (is_file($tmpPath)) {
                    @unlink($tmpPath);
                }
            }

            if ($onProgress !== null) {
                $onProgress($index + 1, $total, $url);
            }
        }

        return new ImportResult(
            imported: $imported,
            skipped: $skipped,
            mediaCopied: $mediaCopied,
            message: sprintf('URL list import (%d input(s), %d errors)', $total, count($errors)),
            extras: ['errors' => $errors],
        );
    }

    /**
     * @return list<string> normalised URLs
     */
    private function parseUrls(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Allow CSV "<url>,<...>" format — only the first column is the URL.
            if (str_contains($line, ',')) {
                $line = trim((string)strtok($line, ','));
            }
            if (!preg_match('#^https?://#i', $line)) {
                continue; // reject non-http(s) (no file://, javascript:, etc.)
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * Fetch the URL with size + timeout caps. Returns body + derived
     * metadata for the importer to write.
     *
     * @return array{body:string, mime:string, ext:string, filename:string, title:string}
     */
    private function fetchUrl(string $url, int $timeout, int $maxBytes): array
    {
        $response = $this->requestFactory->request($url, 'GET', [
            'timeout' => $timeout,
            'allow_redirects' => ['max' => 5, 'strict' => false, 'protocols' => ['http', 'https']],
            'headers' => ['User-Agent' => 'ws_meilisearch/UrlListImporter'],
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('HTTP %d', $status));
        }
        $contentLength = (int)$response->getHeaderLine('content-length');
        if ($contentLength > 0 && $contentLength > $maxBytes) {
            throw new \RuntimeException(sprintf('Content-Length %d > cap %d', $contentLength, $maxBytes));
        }
        $body = (string)$response->getBody();
        if (strlen($body) > $maxBytes) {
            throw new \RuntimeException(sprintf('Body size %d > cap %d', strlen($body), $maxBytes));
        }
        if ($body === '') {
            throw new \RuntimeException('Empty response body');
        }
        // Header Content-Type ("text/html; charset=utf-8") is the
        // remote server's claim. We prefer PHP's finfo over the bytes
        // because TYPO3 v14's resource consistency check uses content-
        // based detection too — saving a file as .html when finfo sees
        // text/xml (e.g. XHTML 1.0 Transitional with an <?xml prolog)
        // makes addFile() reject the upload. We detect from the body,
        // pick the matching extension, then trust the result.
        $headerMime = strtolower(trim((string)strtok((string)$response->getHeaderLine('content-type'), ';')));
        $detectedMime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $mime = $detectedMime !== '' ? $detectedMime : $headerMime;
        $ext = self::MIME_TO_EXT[$mime] ?? self::MIME_TO_EXT[$headerMime] ?? null;

        // Derive a filename from the URL path; if the path tail has no
        // extension, append the one we resolved. If the path tail HAS
        // an extension but the detected mime maps to a different one
        // (server lies / actual content differs), swap to the detected
        // ext so TYPO3's consistency check passes.
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = basename($path);
        if ($base === '' || $base === '/') {
            $base = 'index';
        }
        $pathExt = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if ($ext !== null && $pathExt !== $ext) {
            $base = ($pathExt !== '' ? pathinfo($base, PATHINFO_FILENAME) : $base) . '.' . $ext;
        } elseif ($ext === null) {
            $ext = $pathExt !== '' ? $pathExt : 'bin';
        }

        // Extract <title> even when the response is served as
        // text/xml (DITA-OT XHTML) — the tag layout is identical.
        $title = '';
        if (str_starts_with($mime, 'text/') || $mime === 'application/xhtml+xml') {
            $title = $this->extractHtmlTitle($body);
        }

        return [
            'body' => $body,
            'mime' => $mime,
            'ext' => (string)$ext,
            'filename' => $base,
            'title' => $title,
        ];
    }

    /**
     * Pull the <title> element from an HTML response so the knowledge resource has
     * a human-readable title instead of the URL tail. Returns '' if no
     * title is present.
     */
    private function extractHtmlTitle(string $html): string
    {
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    /**
     * Cheap HTML → text fallback for the case where Tika doesn't accept
     * text/html (default Tika mime list doesn't include it). Strips
     * <script> / <style> and collapses whitespace.
     */
    private function htmlToText(string $html): string
    {
        $html = (string)preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = (string)preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}

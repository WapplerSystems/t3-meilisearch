<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Tika;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves a FAL file into searchable text via Apache Tika, applying mime /
 * size policy and caching extracted text by file content hash.
 *
 * The cache is keyed by `tika_<sha1>` so identical content across multiple
 * sys_file rows extracts exactly once, and a content change naturally
 * invalidates the entry by producing a new sha1.
 */
final class TextExtractor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private readonly FrontendInterface $cache;

    public function __construct(
        private readonly TikaClient $tikaClient,
        CacheManager $cacheManager,
    ) {
        $this->cache = $cacheManager->getCache('ws_meilisearch_tika');
    }

    public function extract(File $file, Site $site): ExtractionResult
    {
        $config = $this->readConfig($site);
        if ($config['url'] === '') {
            return ExtractionResult::skipped('Tika disabled for site (empty meilisearch.tika.url)');
        }

        if ($file->isMissing()) {
            return ExtractionResult::skipped('File missing on storage');
        }

        $mime = (string)$file->getMimeType();
        if (!$this->isMimeAllowed($mime, $config['allowedMimeTypes'])) {
            return ExtractionResult::skipped('Mime type "' . $mime . '" not in allowlist');
        }

        $size = (int)$file->getSize();
        if ($size > $config['maxFileSize']) {
            return ExtractionResult::skipped(\sprintf('File size %d > limit %d', $size, $config['maxFileSize']));
        }

        $sha1 = $this->fingerprint($file);
        if ($sha1 === '') {
            return ExtractionResult::failed('Cannot compute content hash');
        }

        $cacheKey = 'tika_' . $sha1;
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached)) {
            return ExtractionResult::success($cached);
        }

        $contents = $this->loadContents($file);
        if ($contents === null) {
            return ExtractionResult::failed('Cannot read file contents');
        }

        $result = $this->tikaClient->extractText($config['url'], $contents, $mime, $config['timeout']);
        if ($result->status === ExtractionResult::SUCCESS) {
            // Cache forever — entries become unreachable when their sha1 stops
            // matching any sys_file row, and SimpleFileBackend handles GC.
            $this->cache->set($cacheKey, $result->text, ['tika']);
        }
        return $result;
    }

    /**
     * @return array{url: string, timeout: int, maxFileSize: int, allowedMimeTypes: list<string>}
     */
    private function readConfig(Site $site): array
    {
        $settings = $site->getSettings();
        $mimes = $settings->get('meilisearch.tika.allowedMimeTypes', []);
        if (is_string($mimes)) {
            $mimes = array_values(array_filter(array_map('trim', explode(',', $mimes))));
        }
        return [
            'url' => trim((string)$settings->get('meilisearch.tika.url', '')),
            'timeout' => max(1, (int)$settings->get('meilisearch.tika.timeout', 60)),
            'maxFileSize' => max(0, (int)$settings->get('meilisearch.tika.maxFileSize', 52428800)),
            'allowedMimeTypes' => is_array($mimes) ? array_values(array_map('strval', $mimes)) : [],
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function isMimeAllowed(string $mime, array $allowed): bool
    {
        if ($mime === '' || $allowed === []) {
            return false;
        }
        return in_array($mime, $allowed, true);
    }

    private function fingerprint(File $file): string
    {
        $sha1 = (string)($file->getProperty('sha1') ?? '');
        if ($sha1 !== '') {
            return $sha1;
        }
        // Fallback for rows missing sha1 — let FAL compute it (also persists).
        try {
            return (string)$file->getStorage()->hashFile($file, 'sha1');
        } catch (\Throwable) {
            return '';
        }
    }

    private function loadContents(File $file): ?string
    {
        try {
            $contents = $file->getContents();
            return $contents === false ? null : $contents;
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not read FAL file {uid}: {message}', [
                'uid' => $file->getUid(),
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\FileExtraction;

use Lochmueller\Index\FileExtraction\Extractor\FileExtractionInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Integration\ExtIndex\Context\IndexingContext;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;
use WapplerSystems\Meilisearch\Service\Tika\TextExtractor;

/**
 * Routes EXT:index's file-extractor pipeline (tag `index.file_extractor`)
 * through ws_meilisearch's Apache-Tika TextExtractor.
 *
 * Site resolution order:
 *   1. IndexingContext — set by IndexingContextMiddleware from the in-flight
 *      FileMessage's siteIdentifier. The correct path under normal indexing.
 *   2. Fallback: first site with `meilisearch.tika.url` configured. Reached
 *      only when the extractor is called outside the messenger pipeline
 *      (manual / test paths). Logged as a warning because the result depends
 *      on SiteFinder iteration order.
 */
final class TikaFileExtractor implements FileExtractionInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly TextExtractor $textExtractor,
        private readonly SiteFinder $siteFinder,
        private readonly IndexingContext $indexingContext,
    ) {}

    public function getFileGroupName(): string
    {
        return 'tika';
    }

    public function getFileGroupLabel(): string
    {
        return 'Apache Tika (PDF / Office / RTF / EPUB)';
    }

    public function getFileGroupIconIdentifier(): string
    {
        return 'mimetypes-pdf';
    }

    public function getFileExtensions(): array
    {
        return [
            'pdf',
            'doc', 'docx',
            'xls', 'xlsx',
            'ppt', 'pptx',
            'odt', 'ods', 'odp',
            'rtf',
            'epub',
            'txt',
        ];
    }

    public function getFileContent(FileInterface $file): string
    {
        if (!$file instanceof File) {
            return '';
        }

        $site = $this->resolveSite($file);
        if ($site === null) {
            $this->logger?->info('TikaFileExtractor skipped {ident}: no site with meilisearch.tika.url configured', [
                'ident' => $file->getCombinedIdentifier(),
            ]);
            return '';
        }

        $result = $this->textExtractor->extract($file, $site);
        if ($result->status === ExtractionResult::SUCCESS) {
            return $result->text;
        }
        $this->logger?->debug('Tika returned non-success for {ident}: {status} {message}', [
            'ident' => $file->getCombinedIdentifier(),
            'status' => $result->status,
            'message' => $result->reason,
        ]);
        return '';
    }

    private function resolveSite(File $file): ?Site
    {
        $site = $this->indexingContext->getCurrentSite();
        if ($site !== null) {
            return $site;
        }

        $fallback = $this->firstSiteWithTika();
        if ($fallback !== null) {
            $this->logger?->warning(
                'TikaFileExtractor: no IndexingContext site for {ident} — falling back to first configured site {fallback}. This is fine for single-site setups but ambiguous in multi-site with diverging Tika configs.',
                ['ident' => $file->getCombinedIdentifier(), 'fallback' => $fallback->getIdentifier()],
            );
        }
        return $fallback;
    }

    private function firstSiteWithTika(): ?Site
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (trim((string)$site->getSettings()->get('meilisearch.tika.url', '')) !== '') {
                return $site;
            }
        }
        return null;
    }
}

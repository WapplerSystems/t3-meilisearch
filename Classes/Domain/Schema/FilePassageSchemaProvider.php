<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use Meilisearch\Client;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\BoostCalculator;
use WapplerSystems\Meilisearch\Service\LanguageDetector;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;
use WapplerSystems\Meilisearch\Service\Tika\TextExtractor;

/**
 * Indexes the pages of a document as separate passages, so the assistant can
 * ground an answer in page 30 of a 150-page manual and cite it as such.
 *
 * A `file` document carries the whole extracted text, and the assistant only
 * ever sees the first meilisearch.rag.maxContextChars of it — the cover and
 * the table of contents. Retrieval found the right manual and the answer came
 * from somewhere else. A passage is one page (long pages split into chunks
 * with a little overlap), short enough to be passed in full.
 *
 * Off unless meilisearch.passages.enabled is set. Passages are hidden from
 * the visitor-facing search (SearchService excludes the type like knowledge
 * resources) and exist for RAG retrieval.
 *
 * Unlike `file`, a passage is written once per file and not once per site
 * language: the text of a PDF does not change with the metadata overlay, and
 * six copies would cost six embeddings per page. `language` therefore holds
 * every site language id (Meilisearch matches `language = 2` against an
 * array), and `contentLanguage` tells which language the text is in.
 *
 * Document ids: `file-{uid}-p{page}` and `file-{uid}-p{page}-c{n}` for the
 * further chunks of a long page; `parentId` is the id of the file document.
 * The number of passages of a file changes with its content, so passages are
 * removed by filter (StaleDocumentsInterface) rather than by id.
 */
final class FilePassageSchemaProvider implements SchemaProviderInterface, PreReindexCleanupInterface, StaleDocumentsInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const TYPE = 'file_passage';

    public function __construct(
        private readonly FileSchemaProvider $files,
        private readonly TextExtractor $textExtractor,
        private readonly BoostCalculator $boostCalculator,
        private readonly LanguageDetector $languageDetector,
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
        return 'file-' . $uid . '-p1';
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        // How many passages a file had is not known without reading it;
        // staleDocumentsFilter() removes them all.
        return [];
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        if (!$this->enabled($site)) {
            return;
        }
        $eligible = $this->files->eligibleFile($uid, $site);
        if ($eligible === null || !$this->acceptsExtension($eligible[0], $site)) {
            return;
        }
        yield from $this->documentsFor($eligible[0], $eligible[1], $site);
    }

    public function iterateDocuments(Site $site): iterable
    {
        if (!$this->enabled($site)) {
            return;
        }
        foreach ($this->files->eligibleFiles($site) as [$file, $publicUrl]) {
            if ($this->acceptsExtension($file, $site)) {
                yield from $this->documentsFor($file, $publicUrl, $site);
            }
        }
    }

    public function getAdditionalFields(): array
    {
        return [
            new TextField('parentId', searchable: false, filterable: true),
            new IntegerField('page', filterable: true, sortable: true),
            new IntegerField('passageSeq', filterable: true),
        ];
    }

    public function staleDocumentsFilter(int $uid, int $keptCount): string
    {
        return sprintf(
            'type = "%s" AND parentId = "file-%d" AND passageSeq >= %d',
            self::TYPE,
            $uid,
            max(0, $keptCount),
        );
    }

    /**
     * Passages of a site that switched them off, of extensions no longer
     * listed and of files no longer referenced on a visible page would
     * otherwise stay in the index for good: a reindex only reaches what it
     * still yields.
     */
    public function cleanupBeforeReindex(Site $site, Client $client, string $indexName): int
    {
        $filters = [];
        if ($this->enabled($site)) {
            $filters[] = sprintf(
                'type = "%s" AND extension NOT IN [%s]',
                self::TYPE,
                implode(', ', array_map(static fn (string $e): string => '"' . addcslashes($e, '"\\') . '"', $this->extensions($site))),
            );
            // Files no longer referenced on a visible page (deduplicateFiles),
            // the same rule FileSchemaProvider applies to its documents.
            $referenced = $this->files->referencedFileUids($site);
            if ($referenced !== null && $referenced !== []) {
                $filters[] = $this->files->notReferencedFilter(self::TYPE, $referenced);
            }
        } else {
            $filters[] = sprintf('type = "%s"', self::TYPE);
        }
        try {
            if ((int)($client->index($indexName)->stats()['numberOfDocuments'] ?? 0) === 0) {
                return 0;
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('FilePassageSchemaProvider pre-reindex cleanup failed: {message}', ['message' => $e->getMessage()]);
            return 0;
        }
        $deleted = 0;
        foreach ($filters as $filter) {
            try {
                $task = $client->index($indexName)->deleteDocuments(['filter' => $filter]);
                $result = $client->waitForTask($task['taskUid'] ?? null, timeoutInMs: 30000);
                $deleted += (int)($result['details']['deletedDocuments'] ?? 0);
            } catch (\Throwable $e) {
                $this->logger?->warning('FilePassageSchemaProvider pre-reindex cleanup failed: {message}', ['message' => $e->getMessage()]);
            }
        }
        return $deleted;
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    private function documentsFor(File $file, string $publicUrl, Site $site): iterable
    {
        try {
            $result = $this->textExtractor->extractPages($file, $site);
        } catch (\Throwable $e) {
            $this->logger?->info('FilePassageSchemaProvider extraction skipped for {uid}: {message}', [
                'uid' => $file->getUid(),
                'message' => $e->getMessage(),
            ]);
            return;
        }
        if ($result->status !== ExtractionResult::SUCCESS || $result->pages === []) {
            return;
        }

        $settings = $site->getSettings();
        $maxChars = max(400, (int)$settings->get('meilisearch.passages.maxChars', 2400));
        $overlap = max(0, min((int)($maxChars / 2), (int)$settings->get('meilisearch.passages.overlapChars', 300)));
        $minChars = max(0, (int)$settings->get('meilisearch.passages.minChars', 80));

        $uid = (int)$file->getUid();
        $title = $this->files->titleFor($file);
        $accessGroups = $this->files->accessGroupsFor($file);
        $languages = array_values(array_map(static fn ($l): int => $l->getLanguageId(), $site->getLanguages()));
        // One detection for the whole file: a single page is too short for a
        // reliable guess, and bilingual manuals would flip from page to page.
        $contentLanguage = $this->languageDetector->detect($site, $result->text);
        $boost = $this->boostCalculator->compositeFor($site, self::TYPE, null);
        $paged = count($result->pages) > 1;

        $seq = 0;
        foreach ($result->pages as $index => $pageText) {
            $page = $index + 1;
            foreach (self::chunk($pageText, $maxChars, $overlap) as $chunkNo => $text) {
                if (mb_strlen($text) < $minChars) {
                    continue;
                }
                yield [
                    'id' => 'file-' . $uid . '-p' . $page . ($chunkNo > 0 ? '-c' . $chunkNo : ''),
                    'type' => self::TYPE,
                    'uid' => $uid,
                    'pid' => 0,
                    'language' => $languages,
                    'contentLanguage' => $contentLanguage,
                    'title' => $title,
                    'content' => $text,
                    // #page=N opens the built-in PDF viewers of Chrome,
                    // Firefox and Edge on that page; other formats have no
                    // pages to jump to.
                    'uri' => $paged ? $publicUrl . '#page=' . $page : $publicUrl,
                    'parentId' => 'file-' . $uid,
                    'page' => $paged ? $page : 0,
                    'passageSeq' => $seq++,
                    'extension' => strtolower((string)$file->getExtension()),
                    'mimeType' => (string)$file->getMimeType(),
                    'fileSize' => (int)$file->getSize(),
                    'accessGroups' => $accessGroups,
                    'boost' => $boost,
                ];
            }
        }
    }

    /**
     * Splits a page at line breaks into pieces of at most $maxChars, each
     * starting with the last $overlap characters of the previous one — a
     * table cut in half stays readable from either side. A single line
     * longer than $maxChars is cut hard.
     *
     * @return list<string>
     */
    public static function chunk(string $text, int $maxChars, int $overlap): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }
        $lines = [];
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            while (mb_strlen($line) > $maxChars) {
                $lines[] = mb_substr($line, 0, $maxChars);
                $line = mb_substr($line, $maxChars);
            }
            $lines[] = $line;
        }
        $chunks = [];
        $current = '';
        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (mb_strlen($candidate) > $maxChars && $current !== '') {
                $chunks[] = $current;
                $tail = $overlap > 0 ? mb_substr($current, -$overlap) : '';
                $current = $tail === '' ? $line : $tail . "\n" . $line;
                if (mb_strlen($current) > $maxChars) {
                    $current = $line;
                }
                continue;
            }
            $current = $candidate;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return array_values(array_map('trim', $chunks));
    }

    private function enabled(Site $site): bool
    {
        return (bool)$site->getSettings()->get('meilisearch.passages.enabled', false);
    }

    private function acceptsExtension(File $file, Site $site): bool
    {
        return in_array(strtolower((string)$file->getExtension()), $this->extensions($site), true);
    }

    /**
     * @return list<string>
     */
    private function extensions(Site $site): array
    {
        $raw = $site->getSettings()->get('meilisearch.passages.extensions', ['pdf']);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        $out = [];
        foreach ((array)$raw as $ext) {
            $ext = ltrim(strtolower(trim((string)$ext)), '.');
            if ($ext !== '') {
                $out[] = $ext;
            }
        }
        return $out === [] ? ['pdf'] : array_values(array_unique($out));
    }
}

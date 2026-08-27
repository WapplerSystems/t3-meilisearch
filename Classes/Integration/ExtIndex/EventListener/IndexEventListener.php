<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\EventListener;

use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use WapplerSystems\Meilisearch\Service\Indexing\DocumentBatchWriter;
use WapplerSystems\Meilisearch\Service\Indexing\EmbeddingFailedException;
use WapplerSystems\Meilisearch\Event\BeforeDocumentIndexedEvent;
use WapplerSystems\Meilisearch\Integration\ExtIndex\Schema\ExtIndexOrigin;
use WapplerSystems\Meilisearch\Service\BoostCalculator;
use WapplerSystems\Meilisearch\Service\EmbeddingPrecomputer;
use WapplerSystems\Meilisearch\Service\HtmlToText;
use WapplerSystems\Meilisearch\Service\LanguageDetector;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Maps EXT:index page/file events into the ws_meilisearch SEAL engine.
 *
 * Hears Lochmueller\Index\Event\{IndexPageEvent,IndexFileEvent}, maps the
 * event payload to the ws_meilisearch unified document schema (id, type,
 * uid/pid/language, title, content, …), dispatches the normal
 * BeforeDocumentIndexedEvent so existing ws_meilisearch listeners
 * (cache, redactors, embedder hooks) still see the document, then calls
 * SearchEngineFactory::createForSite() to push via SEAL.
 *
 * Document id strategy mirrors ws_meilisearch's SchemaProviders:
 *   pages-<uid>             for pages (uid = pageUid)
 *   sys_file-<uid>          for files where we can resolve sys_file.uid,
 *                            else sys_file-h<crc> as a stable fallback.
 *
 * Batching: EXT:index delivers one page per Messenger message, so the naive
 * mapping is one Meilisearch write per document — and Meilisearch processes its
 * task queue serially at roughly half a second per task. Measured on a 27.000-page
 * crawl that ceiling sits near 1,4 documents/second and does not move with more
 * worker processes (three workers: 1,78/s, i.e. +25 % for 3× the processes).
 * The writer this class already used can buffer, so documents are now collected
 * across messages and flushed as a batch. See {@see resolveCrawlBatchSize()} for
 * the trade-off that keeps the default at 1.
 */
final class IndexEventListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * One buffering writer per site + index, reused across Messenger messages.
     * The class is a shared service, so the buffer survives from one handled
     * message to the next inside the same worker process.
     *
     * @var array<string, DocumentBatchWriter>
     */
    private array $writers = [];

    private bool $shutdownFlushRegistered = false;

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly BoostCalculator $boostCalculator,
        private readonly EmbeddingPrecomputer $embeddingPrecomputer,
        private readonly LanguageDetector $languageDetector,
        private readonly HtmlToText $htmlToText,
    ) {}

    #[AsEventListener('ws-meilisearch-ext-index-page')]
    public function onIndexPage(IndexPageEvent $event): void
    {
        $engine = $this->engineFactory->createForSite($event->site);
        if ($engine === null) {
            return;
        }
        $indexName = $this->engineFactory->getIndexName($event->site);

        // Enrich with SEO meta straight from the pages row. The IndexPageEvent
        // only carries title + rendered content; subtitle/description/abstract/
        // keywords/pid live in the database and are valuable for ranking and
        // facets, so we fetch them on the spot. One row lookup per page is
        // cheap next to the rendering work EXT:index already did.
        $pageMeta = $this->fetchPageMeta($event->pageUid);

        // Doc id MUST include the language for non-default languages —
        // otherwise every language overlay of the same page overwrites
        // the previous doc (same uid → same id). Matches the
        // FileSchemaProvider convention: lang 0 keeps the legacy
        // `pages-{uid}` form for backward compatibility, lang N gets
        // `pages-{uid}-l{N}`.
        $docId = 'pages-' . $event->pageUid
            . ($event->language > 0 ? '-l' . $event->language : '');
        $document = [
            'id' => $docId,
            'type' => 'page',
            'uid' => $event->pageUid,
            'pid' => $pageMeta['pid'],
            'language' => $event->language,
            'title' => $this->stripWebsiteTitleSuffix($event->title, $event->site, $event->language),
            'subtitle' => $pageMeta['subtitle'],
            'description' => $pageMeta['description'],
            'abstract' => $pageMeta['abstract'],
            'keywords' => $pageMeta['keywords'],
            'content' => $this->normalize($event->content),
            'uri' => $event->uri,
            'boost' => $this->boostCalculator->compositeFor($event->site, 'page', $pageMeta['boost']),
            'site' => $event->site->getIdentifier(),
            'indexProcessId' => $event->indexProcessId,
            'accessGroups' => $event->accessGroups,
            'contentLanguage' => $this->languageDetector->detect($event->site, $this->normalize($event->content)),
        ];

        $this->save($engine, $indexName, $document, new ExtIndexOrigin('pages'), $event->site);
    }

    #[AsEventListener('ws-meilisearch-ext-index-file')]
    public function onIndexFile(IndexFileEvent $event): void
    {
        $engine = $this->engineFactory->createForSite($event->site);
        if ($engine === null) {
            return;
        }
        $indexName = $this->engineFactory->getIndexName($event->site);

        // EXT:index passes a combined fileIdentifier (storage:path). Convert to
        // a stable doc id; for files that also exist in sys_file we prefer the
        // numeric uid so realtime FAL-event removal can reach the document.
        $sysFileUid = $this->resolveSysFileUid($event->fileIdentifier);
        $docId = $sysFileUid !== null
            ? 'sys_file-' . $sysFileUid
            : 'sys_file-h' . hash('crc32b', $event->fileIdentifier);

        $document = [
            'id' => $docId,
            'type' => 'file',
            'uid' => $sysFileUid ?? 0,
            'pid' => 0,
            'language' => 0,
            'title' => $event->title,
            'subtitle' => '',
            'description' => '',
            'abstract' => '',
            'keywords' => '',
            'content' => $this->normalize($event->content),
            'uri' => $event->uri,
            // Files have no per-record TCA boost (sys_file isn't editor-
            // curated for ranking purposes) — type-level multiplier only.
            'boost' => $this->boostCalculator->compositeFor($event->site, 'file', null),
            // FAL access control: look up sys_file_metadata.fe_groups for
            // the resolved sys_file uid. Files outside sys_file (hash-id
            // path) default to public — that path covers external files
            // EXT:index discovered but FAL doesn't know about, so per-doc
            // restrictions can't be reasoned about anyway.
            'accessGroups' => $sysFileUid !== null ? $this->fetchFileAccessGroups($sysFileUid) : [],
            'fileIdentifier' => $event->fileIdentifier,
            'site' => $event->site->getIdentifier(),
            'indexProcessId' => $event->indexProcessId,
            'contentLanguage' => $this->languageDetector->detect($event->site, $this->normalize($event->content)),
        ];

        $this->save($engine, $indexName, $document, new ExtIndexOrigin('sys_file'), $event->site);
    }

    /**
     * @param array<string,mixed> $document
     */
    private function save(
        object $engine,
        string $indexName,
        array $document,
        ExtIndexOrigin $origin,
        \TYPO3\CMS\Core\Site\Entity\Site $site,
    ): void {
        try {
            $before = new BeforeDocumentIndexedEvent($origin, $document);
            $this->eventDispatcher->dispatch($before);
            $doc = $before->document;
            $writer = $this->writerFor($site, $indexName, $engine);
            // push() flushes on its own once the batch is full. With the
            // default batch size of 1 that is the historical behaviour:
            // one document, one Meilisearch write, before the message is
            // acknowledged.
            $writer->push($doc, $origin);
        } catch (EmbeddingFailedException $e) {
            // Do NOT swallow this one. The index runs a userProvided
            // embedder, so a document without a vector is rejected by
            // Meilisearch asynchronously — the crawl would ack its
            // Messenger message, empty the queue and leave the page
            // silently on its old state, with the only trace in
            // `GET /tasks?statuses=failed`. Letting the exception escape
            // fails the message instead, so Messenger retries it once the
            // provider's quota window has moved on.
            $this->logger?->warning(
                'EXT:index-integration: embedding unavailable for document {id} — failing the message so it is retried: {message}',
                ['id' => $document['id'] ?? '?', 'message' => $e->getMessage()],
            );
            throw $e;
        } catch (\Throwable $e) {
            $this->logger?->error('EXT:index-integration failed to index document {id}: {message}', [
                'id' => $document['id'] ?? '?',
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * The buffering writer for this site + index, created on first use.
     *
     * The writer fingerprints every document, re-uses the vector already in the
     * index when the text is unchanged and only then falls back to the embedding
     * provider. That is what makes a re-crawl affordable: EXT:index re-visits
     * every page, but only pages whose text actually changed cost tokens.
     *
     * strict: true — an embedding failure must escape the caller so Messenger
     * retries the message instead of acknowledging a document that never
     * reached the index.
     */
    private function writerFor(
        \TYPO3\CMS\Core\Site\Entity\Site $site,
        string $indexName,
        object $engine,
    ): DocumentBatchWriter {
        $key = $site->getIdentifier() . '/' . $indexName;
        if (isset($this->writers[$key])) {
            return $this->writers[$key];
        }

        $writer = new DocumentBatchWriter(
            $site,
            $this->engineFactory->createClientForSite($site),
            $engine instanceof \CmsIg\Seal\Engine ? $engine : null,
            $indexName,
            $indexName,
            $this->embeddingPrecomputer,
            $this->eventDispatcher,
            $this->embeddingPrecomputer->isEnabledForSite($site),
            true,
            false,
            true,
            $this->resolveCrawlBatchSize($site),
        );
        if ($this->logger !== null) {
            $writer->setLogger($this->logger);
        }
        $this->registerShutdownFlush();

        return $this->writers[$key] = $writer;
    }

    /**
     * How many crawled documents may wait in the buffer before they are written.
     *
     * Default 1, i.e. unchanged behaviour, because batching moves the write
     * AFTER the Messenger acknowledgement: with a batch of N, a worker that is
     * killed mid-batch loses up to N documents together with their queue
     * entries, and the only symptom is a page that silently keeps its old
     * indexed state. Raise it deliberately for a bulk reindex — at 100 the same
     * corpus that crawls in hours is done in minutes — and lower it again for
     * steady-state operation, or accept the window.
     *
     * Forced back to 1 while a userProvided embedder computes vectors in PHP.
     * There the caller relies on an EmbeddingFailedException escaping so
     * Messenger retries THAT message; once documents are batched the exception
     * surfaces while handling some later message and would fail the wrong one,
     * acknowledging the documents that actually failed.
     */
    private function resolveCrawlBatchSize(\TYPO3\CMS\Core\Site\Entity\Site $site): int
    {
        if ($this->embeddingPrecomputer->isEnabledForSite($site)) {
            return 1;
        }
        $configured = (int)$site->getSettings()->get('meilisearch.indexing.crawlBatchSize', 1);

        return max(1, min(1000, $configured));
    }

    /**
     * Write out whatever is still buffered. Called when the worker runs dry and
     * when it stops; without the idle flush the last partial batch of a crawl
     * would sit in memory until the process happens to exit.
     */
    public function flushBuffered(): void
    {
        foreach ($this->writers as $writer) {
            try {
                $writer->flush();
            } catch (\Throwable $e) {
                $this->logger?->error('EXT:index-integration failed to flush buffered documents: {message}', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    #[AsEventListener('ws-meilisearch-ext-index-worker-idle')]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($event->isWorkerIdle()) {
            $this->flushBuffered();
        }
    }

    #[AsEventListener('ws-meilisearch-ext-index-worker-stopped')]
    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $this->flushBuffered();
    }

    /**
     * Last line of defence for entry points that emit no worker events at all —
     * a backend request saving a record, or a CLI process exiting early. Without
     * it a buffer filled outside a Messenger worker would never be written.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->shutdownFlushRegistered) {
            return;
        }
        $this->shutdownFlushRegistered = true;
        register_shutdown_function(function (): void {
            $this->flushBuffered();
        });
    }

    /**
     * EXT:index glues the site title onto every page title
     * (`DatabaseIndexingHandler`: `$pageRow['title'] . ' | ' . websiteTitle`,
     * and identically in its News/Address content types). In a search index
     * that suffix is noise: it repeats on every single hit, it is the same
     * for the whole site, and it bleeds into the embedder's
     * documentTemplate (`{{ doc.title }}. {{ doc.content }}`) where it
     * costs tokens and blurs the vector. Peel it back off — per-language
     * website title included, since a site may override it per language.
     *
     * Opt out with `meilisearch.indexing.stripWebsiteTitle: false`.
     */
    private function stripWebsiteTitleSuffix(string $title, \TYPO3\CMS\Core\Site\Entity\Site $site, int $language): string
    {
        if (!(bool)$site->getSettings()->get('meilisearch.indexing.stripWebsiteTitle', true)) {
            return $title;
        }

        $websiteTitles = [(string)($site->getConfiguration()['websiteTitle'] ?? '')];
        try {
            $websiteTitles[] = $site->getLanguageById($language)->getWebsiteTitle();
        } catch (\Throwable) {
            // Language not configured on this site — the site-level title is all we have.
        }

        foreach ($websiteTitles as $websiteTitle) {
            $websiteTitle = trim($websiteTitle);
            if ($websiteTitle === '') {
                continue;
            }
            $suffix = ' | ' . $websiteTitle;
            if (!str_ends_with($title, $suffix)) {
                continue;
            }
            $stripped = rtrim(substr($title, 0, -strlen($suffix)));
            // A page whose own title is empty would strip down to nothing —
            // an empty title is worse than a redundant one, so keep the original.
            if ($stripped !== '') {
                return $stripped;
            }
        }

        return $title;
    }

    private function normalize(string $content): string
    {
        // EXT:index's database technology hands over HTML; frontend/http
        // technologies do the same. SEAL/Meilisearch indexes plain text.
        return $this->htmlToText->convert($content);
    }

    private function resolveSysFileUid(string $combinedIdentifier): ?int
    {
        try {
            $file = $this->resourceFactory->getFileObjectFromCombinedIdentifier($combinedIdentifier);
            $uid = (int)$file->getUid();
            return $uid > 0 ? $uid : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{pid:int,subtitle:string,description:string,abstract:string,keywords:string,boost:int|null}
     */
    private function fetchPageMeta(int $pageUid): array
    {
        $empty = ['pid' => 0, 'subtitle' => '', 'description' => '', 'abstract' => '', 'keywords' => '', 'boost' => null];
        if ($pageUid <= 0) {
            return $empty;
        }
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $row = $qb->select('pid', 'subtitle', 'description', 'abstract', 'keywords', 'tx_wsmeilisearch_boost')
                ->from('pages')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)))
                ->executeQuery()
                ->fetchAssociative();
            if ($row === false) {
                return $empty;
            }
            return [
                'pid' => (int)$row['pid'],
                'subtitle' => (string)$row['subtitle'],
                'description' => (string)$row['description'],
                'abstract' => (string)$row['abstract'],
                'keywords' => (string)$row['keywords'],
                // Column may legitimately be absent during the first deploy
                // before database:updateschema has run — treat that as
                // "normal" (= no boost) so the indexer keeps working.
                'boost' => isset($row['tx_wsmeilisearch_boost']) ? (int)$row['tx_wsmeilisearch_boost'] : null,
            ];
        } catch (\Throwable $e) {
            $this->logger?->debug('EXT:index-integration could not fetch page meta for {uid}: {message}', [
                'uid' => $pageUid,
                'message' => $e->getMessage(),
            ]);
            return $empty;
        }
    }

    /**
     * Resolve `sys_file_metadata.fe_groups` for a sys_file uid. Empty/null
     * → public. Reads only the default-language metadata row; per-language
     * fe_groups overrides on file metadata are not honoured (FAL doesn't
     * use them in its access-checking either).
     *
     * @return list<int>
     */
    private function fetchFileAccessGroups(int $fileUid): array
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
            $row = $qb->select('fe_groups')
                ->from('sys_file_metadata')
                ->where(
                    $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $qb->expr()->eq('sys_language_uid', 0),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable $e) {
            $this->logger?->debug('EXT:index-integration could not fetch file fe_groups for {uid}: {message}', [
                'uid' => $fileUid,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
        $raw = trim((string)($row['fe_groups'] ?? ''));
        if ($raw === '' || $raw === '0') {
            return [];
        }
        $ids = array_map(
            static fn (string $g): int => (int) trim($g),
            explode(',', $raw),
        );
        return array_values(array_filter($ids, static fn (int $g): bool => $g !== 0));
    }
}

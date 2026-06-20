<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\EventListener;

use Lochmueller\Index\Event\IndexFileEvent;
use Lochmueller\Index\Event\IndexPageEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use WapplerSystems\Meilisearch\Event\AfterDocumentIndexedEvent;
use WapplerSystems\Meilisearch\Event\BeforeDocumentIndexedEvent;
use WapplerSystems\Meilisearch\Integration\ExtIndex\Schema\ExtIndexOrigin;
use WapplerSystems\Meilisearch\Service\BoostCalculator;
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
 */
final class IndexEventListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ResourceFactory $resourceFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly BoostCalculator $boostCalculator,
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
            'title' => $event->title,
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
        ];

        $this->save($engine, $indexName, $document, new ExtIndexOrigin('pages'));
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
        ];

        $this->save($engine, $indexName, $document, new ExtIndexOrigin('sys_file'));
    }

    /**
     * @param array<string,mixed> $document
     */
    private function save(object $engine, string $indexName, array $document, ExtIndexOrigin $origin): void
    {
        try {
            $before = new BeforeDocumentIndexedEvent($origin, $document);
            $this->eventDispatcher->dispatch($before);
            /** @phpstan-ignore-next-line — SEAL Engine type is known at runtime */
            $engine->saveDocument($indexName, $before->document);
            $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($origin, $before->document));
        } catch (\Throwable $e) {
            $this->logger?->error('EXT:index-integration failed to index document {id}: {message}', [
                'id' => $document['id'] ?? '?',
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function normalize(string $content): string
    {
        // EXT:index's database technology hands over HTML; frontend/http
        // technologies do the same. SEAL/Meilisearch indexes plain text.
        // Insert a space at every tag boundary first, otherwise strip_tags
        // collapses "Foo</p><p>Bar" into "FooBar".
        $padded = preg_replace('/></u', '> <', $content) ?? $content;
        $stripped = strip_tags($padded);
        $collapsed = preg_replace('/\s+/u', ' ', $stripped);
        return trim((string)$collapsed);
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

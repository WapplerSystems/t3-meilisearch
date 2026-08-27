<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Indexing;

use CmsIg\Seal\Engine;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Exceptions\ApiException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Event\AfterDocumentIndexedEvent;
use WapplerSystems\Meilisearch\Service\EmbedderConfigurator;
use WapplerSystems\Meilisearch\Service\EmbeddingPrecomputer;

/**
 * Buffers documents on their way into Meilisearch and decides, per
 * document, whether it needs to be embedded at all.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this class, every indexing run pushed one document per HTTP
 * request and — with `precompute` on — fetched a fresh embedding for
 * every single one. A full reindex of the LINEAR corpus therefore meant
 * ~45.000 embedding calls and ~45.000 Meilisearch tasks, even when a
 * handful of pages had changed. Scaleway's *tokens per minute* quota
 * cannot absorb that: the run stalls in a silent 429 retry loop after a
 * few thousand documents ("INSUFFICIENT QUOTA … quota tokens per minute.
 * Slow down"), which is why re-indexing on production had been stuck for
 * months and new keyword-only fields never reached the whole corpus.
 *
 * HOW IT AVOIDS THE WORK
 * ----------------------
 * Two fingerprints travel with every document:
 *
 *   docHash    hash of the whole document (minus vectors and the hashes
 *              themselves) — "nothing about this document changed"
 *   embedHash  hash of the exact text handed to the embedder, plus the
 *              embedder identity (source/model/dimensions/truncation) —
 *              "the existing vector is still the right vector"
 *
 * Per flush the writer asks the index for the fingerprints of the
 * documents it is about to write (a cheap request: ids and two short
 * strings, no vectors), and then classifies:
 *
 *   docHash matches      → nothing to do, don't even push (in-place mode)
 *   embedHash matches    → copy the existing vector over, push the new
 *                          keyword fields, spend nothing at the provider
 *   otherwise            → embed, then push
 *
 * Vectors are only ever transferred for the middle case, in a second
 * request — a 3584-dimension vector is ~70 KB of JSON, so fetching them
 * unconditionally would trade a token bill for a bandwidth bill.
 *
 * BOOTSTRAP
 * ---------
 * Documents indexed before this feature existed carry no fingerprints. A
 * document that has a vector of the expected dimension but no embedHash
 * is *adopted*: its vector is treated as current and re-used. That makes
 * the first run after deployment cost zero embeddings instead of
 * re-embedding the entire corpus — which is exactly the trap this class
 * is here to remove. Use `--force-embed` when the corpus really has to
 * be re-vectorised (embedder model change, suspected drift).
 *
 * ZERO-DOWNTIME MODE
 * ------------------
 * When indexAll() builds into a draft index, "skip" is not available —
 * every document must physically land in the draft or the swap would
 * publish an index with holes in it. The writer then reads fingerprints
 * and vectors from the *primary* index and writes everything to the
 * draft: still no embedding cost, but the vectors do go over the wire.
 */
final class DocumentBatchWriter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const HASH_FIELD = 'docHash';
    public const EMBED_HASH_FIELD = 'embedHash';

    /**
     * Give up the whole run after this many consecutive embedding
     * batches failed. A provider that is down, out of credit or
     * misconfigured would otherwise be "handled" by marking all 45.000
     * documents as failed one batch at a time — hours of pointless
     * retrying that ends in a gutted index.
     */
    private const MAX_CONSECUTIVE_FAILED_BATCHES = 3;

    /**
     * Flush early once the buffered documents reach this much text,
     * regardless of the document count. File documents carry the full
     * Tika extraction — a handful of large PDFs can be tens of megabytes
     * on their own, and the CLI runs with a 512 MB memory limit on
     * production. Counting characters is a rough proxy, but it is free;
     * measuring the real payload would mean encoding every document
     * twice.
     */
    private const MAX_BUFFER_CHARS = 8_000_000;

    /** @var list<array{doc:array<string,mixed>,origin:object}> */
    private array $buffer = [];

    private int $consecutiveFailedBatches = 0;

    private int $bufferedChars = 0;

    /**
     * Memoised answer to "can this run look fingerprints up at all?".
     * Null until the first lookup decides.
     */
    private ?bool $lookupAvailable = null;

    /** @var (callable(IndexWriteStats): void)|null */
    private $progressCallback = null;

    public readonly IndexWriteStats $stats;

    /**
     * @param Client|null $client Raw Meilisearch client; null when the site has no
     *                            meilisearch.url — the writer then degrades to SEAL pushes
     *                            with no fingerprint lookup.
     * @param string $writeIndex Index documents are pushed to (draft in zero-downtime mode).
     * @param string|null $readIndex Index the fingerprints/vectors are read from (the primary),
     *                               null to disable re-use entirely.
     * @param bool $precompute Whether vectors are attached in PHP (userProvided embedder).
     * @param bool $allowSkip May unchanged documents be left untouched? False while building a draft.
     * @param bool $forceEmbed Ignore every fingerprint and re-embed everything.
     * @param bool $strict Let embedding failures escape instead of counting them (single-record
     *                     and crawl paths, where the caller must retry the message).
     */
    public function __construct(
        private readonly Site $site,
        private readonly ?Client $client,
        private readonly ?Engine $engine,
        private readonly string $writeIndex,
        private readonly ?string $readIndex,
        private readonly EmbeddingPrecomputer $precomputer,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly bool $precompute,
        private readonly bool $allowSkip,
        private readonly bool $forceEmbed = false,
        private readonly bool $strict = false,
        private readonly int $batchSize = 0,
    ) {
        $this->stats = new IndexWriteStats();
    }

    /**
     * Called after every flush with the running totals — a long reindex
     * is otherwise completely silent for hours, and "is it working or is
     * it stuck in a retry loop?" is the first question an operator has.
     *
     * @param (callable(IndexWriteStats): void)|null $callback
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Queue a document. Flushes automatically once the batch is full.
     *
     * @param array<string,mixed> $document
     */
    public function push(array $document, object $origin): void
    {
        $this->buffer[] = ['doc' => $document, 'origin' => $origin];
        $this->stats->seen++;
        $this->bufferedChars += strlen((string)($document['content'] ?? ''))
            + strlen((string)($document['bodytext'] ?? ''));
        if (count($this->buffer) >= $this->resolveBatchSize()
            || $this->bufferedChars >= self::MAX_BUFFER_CHARS
        ) {
            $this->flush();
        }
    }

    /**
     * Process and push everything queued so far. Safe to call on an
     * empty buffer.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $entries = $this->buffer;
        $this->buffer = [];
        $this->bufferedChars = 0;

        // 1. Fingerprint every document. The hashes are regular index
        //    fields, so they travel with the document and are available
        //    to the next run.
        foreach ($entries as $i => $entry) {
            $doc = $entry['doc'];
            $embedText = $this->precompute ? $this->precomputer->buildEmbedText($this->site, $doc) : '';
            $doc[self::EMBED_HASH_FIELD] = $this->precompute
                ? $this->precomputer->embedHashFor($this->site, $embedText)
                : '';
            $doc[self::HASH_FIELD] = $this->fingerprint($doc);
            $entries[$i]['doc'] = $doc;
            $entries[$i]['embedText'] = $embedText;
        }

        // 2. What does the index already know about these documents?
        $ids = array_values(array_filter(array_map(
            static fn(array $e): string => (string)($e['doc']['id'] ?? ''),
            $entries,
        ), static fn(string $id): bool => $id !== ''));
        $existing = $this->forceEmbed ? [] : $this->fetchFingerprints($ids);

        // 3. Classify.
        /** @var list<array{doc:array<string,mixed>,origin:object}> $toWrite */
        $toWrite = [];
        /** @var array<int,string> $wantVector  index in $toWrite => document id */
        $wantVector = [];
        /** @var array<int,string> $wantEmbedding  index in $toWrite => text */
        $wantEmbedding = [];

        foreach ($entries as $entry) {
            $doc = $entry['doc'];
            $id = (string)($doc['id'] ?? '');
            $known = $existing[$id] ?? null;

            if ($this->allowSkip
                && $known !== null
                && $known['docHash'] !== ''
                && $known['docHash'] === $doc[self::HASH_FIELD]
                && (!$this->precompute || $known['hasVector'])
            ) {
                // Byte-identical document already in the index, with a
                // vector if one is required. Nothing to do.
                $this->stats->skipped++;
                $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($entry['origin'], $doc));
                continue;
            }

            $position = count($toWrite);
            $toWrite[] = ['doc' => $doc, 'origin' => $entry['origin']];

            if (!$this->precompute) {
                continue;
            }
            if ($this->canReuseVector($known, $doc[self::EMBED_HASH_FIELD])) {
                $wantVector[$position] = $id;
            } else {
                $wantEmbedding[$position] = (string)$entry['embedText'];
            }
        }

        // 4. Copy vectors that are still valid — one request for all of them.
        if ($wantVector !== []) {
            $vectors = $this->fetchVectors(array_values($wantVector));
            foreach ($wantVector as $position => $id) {
                $vector = $vectors[$id] ?? null;
                if ($vector === null) {
                    // The document disappeared between the two requests, or
                    // Meilisearch declined to hand the vector back. Fall
                    // back to embedding it rather than pushing it vectorless
                    // (which Meilisearch would silently reject).
                    $wantEmbedding[$position] = $this->precomputer->buildEmbedText(
                        $this->site,
                        $toWrite[$position]['doc'],
                    );
                    continue;
                }
                $toWrite[$position]['doc'] = $this->precomputer->withVector($toWrite[$position]['doc'], $vector);
                $this->stats->reused++;
            }
        }

        // 5. Embed what is genuinely new or changed.
        $dropped = [];
        if ($wantEmbedding !== []) {
            $dropped = $this->embedInto($toWrite, $wantEmbedding);
        }

        // 6. Push.
        $documents = [];
        foreach ($toWrite as $position => $item) {
            if (isset($dropped[$position])) {
                continue;
            }
            $documents[] = $item['doc'];
        }
        if ($documents !== []) {
            $this->write($documents);
            $this->stats->written += count($documents);
        }

        foreach ($toWrite as $position => $item) {
            if (isset($dropped[$position])) {
                continue;
            }
            $this->eventDispatcher->dispatch(new AfterDocumentIndexedEvent($item['origin'], $item['doc']));
        }

        if ($this->progressCallback !== null) {
            ($this->progressCallback)($this->stats);
        }
    }

    /**
     * Fetch embeddings for the flagged positions and write them into
     * $toWrite. Returns the positions that could not be embedded and
     * must therefore NOT be pushed.
     *
     * @param list<array{doc:array<string,mixed>,origin:object}> $toWrite
     * @param array<int,string> $wantEmbedding
     * @return array<int,true>
     */
    private function embedInto(array &$toWrite, array $wantEmbedding): array
    {
        $dropped = [];
        $chunkSize = $this->precomputer->getBatchSize($this->site);

        foreach (array_chunk($wantEmbedding, $chunkSize, true) as $chunk) {
            try {
                $vectors = $this->precomputer->embedTexts($this->site, $chunk);
                $this->consecutiveFailedBatches = 0;
            } catch (EmbeddingFailedException $e) {
                if ($this->strict) {
                    throw $e;
                }
                $this->consecutiveFailedBatches++;
                foreach (array_keys($chunk) as $position) {
                    $dropped[$position] = true;
                    $this->stats->failed++;
                }
                $this->logger?->error(
                    'Embedding failed for {count} documents on site {site} — NOT indexed: {ids} ({msg})',
                    [
                        'count' => count($chunk),
                        'site' => $this->site->getIdentifier(),
                        'ids' => implode(', ', array_map(
                            static fn(int $p): string => (string)($toWrite[$p]['doc']['id'] ?? '?'),
                            array_keys($chunk),
                        )),
                        'msg' => $e->getMessage(),
                    ],
                );
                if ($this->consecutiveFailedBatches >= self::MAX_CONSECUTIVE_FAILED_BATCHES) {
                    throw new EmbeddingFailedException(sprintf(
                        'Aborting run: %d consecutive embedding batches failed on site %s. Last error: %s',
                        $this->consecutiveFailedBatches,
                        $this->site->getIdentifier(),
                        $e->getMessage(),
                    ), 0, $e);
                }
                continue;
            }
            foreach ($vectors as $position => $vector) {
                $toWrite[$position]['doc'] = $this->precomputer->withVector($toWrite[$position]['doc'], $vector);
                $this->stats->embedded++;
            }
        }

        return $dropped;
    }

    /**
     * A stored vector may be re-used when it exists, has the dimension
     * the index expects, and either matches the current embedHash or
     * predates the fingerprinting (bootstrap adoption, see class docs).
     *
     * @param array{docHash:string,embedHash:string,hasVector:bool}|null $known
     */
    private function canReuseVector(?array $known, string $embedHash): bool
    {
        if ($known === null || !$known['hasVector'] || $embedHash === '') {
            return false;
        }
        return $known['embedHash'] === '' || $known['embedHash'] === $embedHash;
    }

    /**
     * Fingerprint of everything about the document except the vector and
     * the fingerprints themselves. Keys are sorted recursively so that a
     * provider reordering its array has no effect.
     *
     * @param array<string,mixed> $document
     */
    private function fingerprint(array $document): string
    {
        unset(
            $document[EmbeddingPrecomputer::VECTOR_FIELD],
            $document[self::HASH_FIELD],
            $document[self::EMBED_HASH_FIELD],
        );
        $this->ksortRecursive($document);
        return hash('xxh128', (string)json_encode($document, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<mixed> $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * Cheap lookup: ids plus the two fingerprints, no vectors. The
     * `hasVector` flag is derived from the presence of an embedHash…
     * except on a corpus that predates fingerprinting, where it has to be
     * probed. Meilisearch has no "does this document have a vector"
     * projection, so the probe rides along on the vector fetch instead:
     * documents without embedHash are optimistically treated as
     * "vector present" and the fetch corrects that assumption.
     *
     * @param list<string> $ids
     * @return array<string,array{docHash:string,embedHash:string,hasVector:bool}>
     */
    private function fetchFingerprints(array $ids): array
    {
        if ($ids === [] || $this->client === null || $this->readIndex === null) {
            return [];
        }
        if ($this->lookupAvailable === false) {
            return [];
        }
        if ($this->lookupAvailable === null) {
            $this->lookupAvailable = $this->readIndexHasDocuments();
            if (!$this->lookupAvailable) {
                return [];
            }
        }
        try {
            $result = $this->client->index($this->readIndex)->getDocuments(
                (new DocumentsQuery())
                    ->setFilter([$this->idFilter($ids)])
                    ->setFields(['id', self::HASH_FIELD, self::EMBED_HASH_FIELD])
                    ->setLimit(count($ids)),
            );
        } catch (ApiException $e) {
            if ($e->errorCode === 'index_not_found') {
                // First run against a brand-new index: nothing to compare
                // against, everything is new. Not an error.
                return [];
            }
            throw $this->lookupFailure($e);
        } catch (\Throwable $e) {
            // The lookup is what keeps a reindex from re-embedding the
            // whole corpus, so a broken lookup must not degrade quietly
            // into "embed everything" — that is the exact failure this
            // class exists to prevent.
            throw $this->lookupFailure($e);
        }
        $known = [];
        foreach ($result->getResults() as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $embedHash = (string)($row[self::EMBED_HASH_FIELD] ?? '');
            $known[$id] = [
                'docHash' => (string)($row[self::HASH_FIELD] ?? ''),
                'embedHash' => $embedHash,
                // Documents written by this class always carry an
                // embedHash when a vector was attached. Documents from
                // before it carry neither — assume a vector is there and
                // let fetchVectors() prove it.
                'hasVector' => true,
            ];
        }
        return $known;
    }

    /**
     * Is there anything to compare against yet?
     *
     * A freshly created index answers no, and that case has to be
     * distinguished from a broken lookup: `filter: id IN [...]` fails on
     * a brand-new index because the index is created first and
     * `filterableAttributes` are pushed in a separate, asynchronous
     * task — for a moment `id` is simply not filterable. Treating that
     * as a fatal lookup failure aborted every `--rebuild` and every
     * first run on a new installation.
     *
     * An empty index also has no fingerprints by definition, so skipping
     * the lookup is not just a workaround, it is the correct answer.
     */
    private function readIndexHasDocuments(): bool
    {
        try {
            $stats = $this->client?->index((string)$this->readIndex)->stats();
        } catch (\Throwable) {
            // Index does not exist yet — nothing to compare against.
            return false;
        }
        return (int)($stats['numberOfDocuments'] ?? 0) > 0;
    }

    /**
     * The fingerprint lookup is what keeps a reindex from re-embedding
     * the whole corpus, so a broken lookup is fatal by design: degrading
     * to "embed everything" is the exact failure mode this class exists
     * to prevent, and on a rate-limited provider it means a stalled run
     * and a half-written index.
     */
    private function lookupFailure(\Throwable $previous): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'Could not read document fingerprints from index %s: %s. '
            . 'If `id` is not filterable there, run ws_meilisearch:apply-settings first; '
            . 'pass --force-embed to re-embed the whole corpus deliberately.',
            (string)$this->readIndex,
            $previous->getMessage(),
        ), 0, $previous);
    }

    /**
     * Second, expensive request: the actual vectors for the documents
     * whose keyword fields changed but whose embedding text did not.
     *
     * @param list<string> $ids
     * @return array<string,list<float>>
     */
    private function fetchVectors(array $ids): array
    {
        if ($ids === [] || $this->client === null || $this->readIndex === null) {
            return [];
        }
        $expected = $this->precomputer->getDimensions($this->site);
        $vectors = [];
        try {
            $result = $this->client->index($this->readIndex)->getDocuments(
                (new DocumentsQuery())
                    ->setFilter([$this->idFilter($ids)])
                    ->setFields(['id'])
                    ->setRetrieveVectors(true)
                    ->setLimit(count($ids)),
            );
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Could not read existing vectors from index {index}: {msg} — affected documents will be re-embedded',
                ['index' => $this->readIndex, 'msg' => $e->getMessage()],
            );
            return [];
        }

        foreach ($result->getResults() as $row) {
            $id = (string)($row['id'] ?? '');
            $vector = $this->extractVector($row);
            if ($id === '' || $vector === null) {
                continue;
            }
            if ($expected > 0 && count($vector) !== $expected) {
                // Embedder was swapped (dimension change) — the old vector
                // is unusable and the document has to be re-embedded.
                continue;
            }
            $vectors[$id] = $vector;
        }
        return $vectors;
    }

    /**
     * Meilisearch answers `_vectors.default` either as a plain array of
     * floats or as `{"embeddings": [[…]], "regenerate": false}` depending
     * on how the vector was written. Accept both.
     *
     * @param array<string,mixed> $row
     * @return list<float>|null
     */
    private function extractVector(array $row): ?array
    {
        $raw = $row[EmbeddingPrecomputer::VECTOR_FIELD][EmbedderConfigurator::EMBEDDER_NAME] ?? null;
        if (is_array($raw) && isset($raw['embeddings']) && is_array($raw['embeddings'])) {
            $raw = $raw['embeddings'][0] ?? null;
        }
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        return array_map(static fn($v): float => (float)$v, array_values($raw));
    }

    /**
     * Meilisearch filter expression selecting exactly these ids.
     *
     * @param list<string> $ids
     */
    private function idFilter(array $ids): string
    {
        $quoted = array_map(
            static fn(string $id): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $id) . '"',
            $ids,
        );
        return 'id IN [' . implode(', ', $quoted) . ']';
    }

    /**
     * @param list<array<string,mixed>> $documents
     */
    private function write(array $documents): void
    {
        if ($this->precompute && $this->client !== null) {
            // Raw push: SEAL's marshaller copies fields by schema
            // definition only, and `_vectors` is a document-level special
            // field, not a schema field — routing through saveDocument()
            // would strip the vector and Meilisearch would reject every
            // document against the userProvided embedder.
            $this->client->index($this->writeIndex)->addDocuments($documents, 'id');
            return;
        }
        if ($this->engine === null) {
            return;
        }
        // One request for the whole batch instead of one per document.
        // saveDocument() would marshall and POST each document on its own, and
        // Meilisearch turns every POST into a task it processes serially at
        // roughly half a second — which capped the EXT:index crawl at ~1,4
        // documents/second regardless of how many worker processes ran.
        // bulk() marshalls through the same adapter code path (so field
        // transformations stay identical) but sends one addDocuments() call per
        // chunk. bulkSize is the batch we already assembled, so it stays one
        // request; the max() guards against SEAL's chunker being handed 0.
        $this->engine->bulk($this->writeIndex, $documents, [], max(1, count($documents)));
    }

    private function resolveBatchSize(): int
    {
        if ($this->batchSize > 0) {
            return $this->batchSize;
        }
        $configured = (int)$this->site->getSettings()->get('meilisearch.indexing.batchSize', 50);
        return max(1, min(1000, $configured));
    }
}

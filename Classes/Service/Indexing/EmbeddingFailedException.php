<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Indexing;

/**
 * Raised when the embedding provider could not deliver a vector for a
 * document — after all configured retries.
 *
 * Why this is an exception and not a silent fallback: the index runs a
 * `userProvided` embedder, and Meilisearch **rejects** every document
 * that arrives without `_vectors.default` ("no vectors provided for
 * document …"). It does so asynchronously in its own task queue, so the
 * push looks successful from PHP while the document never lands. The
 * earlier behaviour — return the document unchanged and let the caller
 * push it anyway — therefore dropped pages without a single line in the
 * TYPO3 log; the only trace was `GET /tasks?statuses=failed`.
 *
 * Callers must treat this as "document not indexed": the reindexer counts
 * it and reports a non-zero exit, and the EXT:index crawl bridge lets it
 * escape so the Messenger message fails and is retried later instead of
 * being acked with the page silently missing.
 */
final class EmbeddingFailedException extends \RuntimeException
{
}

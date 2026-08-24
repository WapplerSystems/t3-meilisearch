<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Indexing;

/**
 * Internal marker for "the provider said try again" — HTTP 429 (quota /
 * rate limit) or 5xx — carrying the server-supplied Retry-After delay
 * when the response had one.
 *
 * Never leaves EmbeddingPrecomputer: it either resolves on a later
 * attempt or is converted into an EmbeddingFailedException once the
 * attempts are used up.
 *
 * @internal
 */
final class RetryableEmbeddingError extends \RuntimeException
{
    public function __construct(string $message, public readonly ?float $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}

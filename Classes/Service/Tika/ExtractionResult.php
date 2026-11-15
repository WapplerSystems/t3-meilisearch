<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Tika;

/**
 * Outcome of one text extraction attempt.
 *
 * Code paths fall in three buckets:
 *   - text was extracted and is non-empty (status = SUCCESS)
 *   - the file was deliberately not sent to Tika (mime not whitelisted,
 *     too big, Tika not configured) (status = SKIPPED)
 *   - Tika was contacted but failed or timed out (status = FAILED)
 */
final class ExtractionResult
{
    public const SUCCESS = 'success';
    public const SKIPPED = 'skipped';
    public const FAILED  = 'failed';

    /**
     * @param list<string> $pages text per page (index = page − 1) when the
     *        extraction was page-wise, otherwise empty
     */
    public function __construct(
        public readonly string $status,
        public readonly string $text = '',
        public readonly string $reason = '',
        public readonly array $pages = [],
    ) {}

    public static function success(string $text): self
    {
        return new self(self::SUCCESS, $text);
    }

    /**
     * @param list<string> $pages
     */
    public static function pages(array $pages): self
    {
        return new self(self::SUCCESS, implode("\n\n", $pages), '', array_values($pages));
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, '', $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, '', $reason);
    }
}

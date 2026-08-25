<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

/**
 * Outcome of one RagTestRunner invocation. Status taxonomy:
 *
 *   - pass    cosine ≥ threshold and RAG returned an answer
 *   - fail    cosine < threshold (real quality regression)
 *   - error   transport / config / RAG provider failure — NOT a
 *             quality signal; rerun after the underlying fix
 */
final readonly class RagTestResult
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const ERROR = 'error';

    /**
     * The pipeline asked a clarifying question instead of answering. Not a
     * failure of the system and not an error either — there simply is no
     * answer to score. Counting it as ERROR made a deliberate behaviour
     * change look like a malfunction and hid which tests were affected.
     */
    public const CLARIFY = 'clarify';

    public function __construct(
        public string $status,
        public ?float $score = null,
        public string $actualAnswer = '',
        public string $error = '',
    ) {}

    public static function pass(float $score, string $actualAnswer): self
    {
        return new self(self::PASS, $score, $actualAnswer);
    }

    public static function fail(float $score, string $actualAnswer): self
    {
        return new self(self::FAIL, $score, $actualAnswer);
    }

    public static function error(string $message, string $actualAnswer = ''): self
    {
        return new self(self::ERROR, null, $actualAnswer, $message);
    }

    public static function clarify(string $clarifyingQuestion): self
    {
        return new self(self::CLARIFY, null, $clarifyingQuestion, 'assistant asked a clarifying question');
    }
}

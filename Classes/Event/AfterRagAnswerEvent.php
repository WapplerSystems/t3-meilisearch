<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use WapplerSystems\Meilisearch\Service\Rag\RagAnswer;

/**
 * Dispatched after RagService::ask has produced its final RagAnswer.
 * Listeners may swap `$answer` to add post-processing (e.g. PII scrub,
 * disclaimer banner, custom citation rendering).
 */
final class AfterRagAnswerEvent
{
    public function __construct(
        public readonly string $question,
        public RagAnswer $answer,
    ) {}
}

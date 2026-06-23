<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\Rag\RagAnswer;

/**
 * Dispatched after RagService::ask / askStreaming has produced its final
 * RagAnswer (for every terminal status: ok, no_context, failed).
 * Listeners may swap `$answer` to add post-processing (e.g. PII scrub,
 * disclaimer banner, custom citation rendering).
 *
 * `$site` and `$languageId` carry the resolved request context so
 * listeners (e.g. RagAnalyticsLogger) don't have to fall back to
 * $GLOBALS['TYPO3_REQUEST'] — which isn't reliably populated inside the
 * streaming middleware that drives the chat widget.
 */
final class AfterRagAnswerEvent
{
    public function __construct(
        public readonly string $question,
        public RagAnswer $answer,
        public readonly ?Site $site = null,
        public readonly ?int $languageId = null,
    ) {}
}

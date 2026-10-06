<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Event;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Dispatched at the start of RagService::ask, before retrieval runs.
 * Listeners may rewrite the question (e.g. spelling correction, intent
 * detection) or mutate the retrieval options (filters, hit count).
 */
final class BeforeRagQueryEvent
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $question,
        public array $options,
        /**
         * The site the question is asked on — lets a listener read its site
         * settings. Null only for callers that predate the argument.
         */
        public readonly ?Site $site = null,
    ) {}
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protocol;

/**
 * Selection of conversations for the protocol list in the backend.
 *
 * All criteria apply to the conversation as a whole: "escalated" and
 * "problems" match a conversation in which at least ONE turn qualifies, and
 * the list then shows the whole conversation — a reviewer needs the turns
 * leading up to the dead end, not the dead end alone.
 */
final class ProtocolFilter
{
    /**
     * A turn counts as a problem when the assistant did not deliver a
     * grounded answer: these statuses, or `ok` without a single citation.
     */
    public const PROBLEM_STATUSES = ['no_context', 'failed', 'clarify', 'aborted'];

    public function __construct(
        public readonly ?string $siteIdentifier = null,
        public readonly ?int $languageId = null,
        public readonly bool $onlyEscalated = false,
        public readonly bool $onlyProblems = false,
        /** Substring of any question or answer in the conversation. */
        public readonly string $search = '',
        /** Exact conversation id (as quoted in a contact-form mail). */
        public readonly string $conversationId = '',
        /** Only conversations with a turn at or after this timestamp. */
        public readonly int $since = 0,
    ) {}
}

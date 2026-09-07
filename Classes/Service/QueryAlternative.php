<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

/**
 * One recovered spelling of a query that DOES have hits, produced by
 * QueryRecoveryService when the query as typed returned nothing.
 *
 * `kind` says how it was derived and doubles as the confidence order —
 * a corrected typo is a much safer thing to apply automatically than a
 * query whose tokens were dropped:
 *
 *   corrected  one token replaced by the word actually in the index
 *              ("objekt enebler" → "objekt enabler")
 *   split      a German compound split into its parts
 *              ("fußbodenauslegen" → "fußboden auslegen")
 *   join       the reverse, two tokens glued together
 *   dropped    tokens that match nothing anywhere were removed
 *              ("rotameter lzs 50 1 - 10 mh" → "rotameter")
 *   relaxed    same query, but Meilisearch may drop trailing tokens
 *              (matchingStrategy=last) — the last resort, and the one
 *              most likely to be off-target, so it is never applied
 *              automatically.
 */
final readonly class QueryAlternative
{
    public const KIND_CORRECTED = 'corrected';
    public const KIND_SPLIT = 'split';
    public const KIND_JOIN = 'join';
    public const KIND_DROPPED = 'dropped';
    public const KIND_RELAXED = 'relaxed';

    /**
     * Kinds safe enough to run for the visitor without asking. `relaxed`
     * is deliberately absent: with "objekt enebler" it matches 543
     * documents that merely contain "objekt", which would look like the
     * search ignored half the input.
     */
    public const AUTO_APPLICABLE = [
        self::KIND_CORRECTED,
        self::KIND_SPLIT,
        self::KIND_JOIN,
    ];

    private const PRIORITY = [
        self::KIND_CORRECTED => 0,
        self::KIND_SPLIT => 1,
        self::KIND_JOIN => 2,
        self::KIND_DROPPED => 3,
        self::KIND_RELAXED => 4,
    ];

    public function __construct(
        public string $query,
        public int $totalHits,
        public string $kind,
    ) {}

    public function getPriority(): int
    {
        return self::PRIORITY[$this->kind] ?? 99;
    }

    public function getIsAutoApplicable(): bool
    {
        return in_array($this->kind, self::AUTO_APPLICABLE, true);
    }
}

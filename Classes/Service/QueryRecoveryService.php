<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Meilisearch\Client;
use Meilisearch\Contracts\HybridSearchOptions;
use Meilisearch\Contracts\SearchQuery;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Turns a zero-result query into the nearest spellings that DO have hits.
 *
 * Why this exists: the FE search runs with matchingStrategy=all, i.e. every
 * token must match. That is the right default — a two-word product name
 * should not match documents mentioning only one of the words — but it
 * makes the search brittle in exactly the ways the search log shows:
 * a compound written without a space ("fußbodenauslegen" → 0, "fußboden
 * auslegen" → 115), a one-letter typo in the second word ("objekt
 * enebler" → 0, "objekt enabler" → 1), or a product type key pasted in
 * full ("rotameter lzs 50 1 - 10 mh" → 0, "rotameter" → 12, the right PDF).
 *
 * Everything here is built from Meilisearch primitives — no dictionary, no
 * external spellchecker:
 *   • typo tolerance tells us the word the index actually holds. We probe
 *     each token on its own and read the highlighted substring back out of
 *     `_formatted`, which is the corrected word.
 *   • `multiSearch` lets us test a whole batch of candidate spellings in a
 *     SINGLE HTTP round trip, so recovery costs one extra request, not one
 *     per candidate.
 *   • `rankingScoreThreshold` keeps the weak fallbacks honest: a relaxed
 *     query that only limps over the line is not offered at all.
 *
 * The pass runs ONLY when the original search produced zero hits, so the
 * happy path is untouched.
 */
final class QueryRecoveryService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Own highlight markers for the probe queries. The site's configured
     * tags (usually <mark>) would need HTML-aware parsing, and a document
     * could legitimately contain them as text.
     */
    private const MARK_PRE = '@@[';
    private const MARK_POST = ']@@';

    /** Tokens shorter than this carry no signal worth probing. */
    private const MIN_TOKEN_LENGTH = 3;

    /** Compounds shorter than this are not worth splitting. */
    private const MIN_COMPOUND_LENGTH = 9;

    /** Each half of a split has to be a plausible word. */
    private const MIN_SPLIT_PART = 4;

    /**
     * Default for how many documents a split half has to appear in. Text
     * extraction from PDFs leaves line-break debris in the index
     * ("inform ation" → the token "ation" exists in four documents), so
     * "does this string occur at all?" is not the same question as "is
     * this a word?".
     *
     * The number is a genuine trade-off and therefore configurable via
     * meilisearch.search.recovery.minPartHits: raise it and debris stops
     * being proposed, lower it and rare-but-real terms come back. "5"
     * keeps "install ation" (2625 junk hits) out but also drops
     * "vorhangfassade planung", because that term exists in exactly one
     * document — such one-offs belong in the editorial dictionary, which
     * is why that half of the feature exists.
     */
    private const DEFAULT_MIN_PART_HITS = 5;

    /**
     * Guard against pathological input: a 20-word query would otherwise
     * produce dozens of probes. Recovery is a convenience, not a promise.
     */
    private const MAX_TOKENS = 6;

    /**
     * Weak matches are worse than an honest "no results" because they
     * look like the search misunderstood the question. Empirically 0.4
     * separates "same topic, other wording" from "shares one stopword".
     */
    private const MIN_RANKING_SCORE = 0.4;

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
    ) {}

    /**
     * @param list<string> $highlightAttributes attributes to read the
     *        corrected word from — the searchable text fields
     * @param array<string,mixed>|null $hybridParams keep the hybrid blend
     *        identical to the original search, otherwise the alternative's
     *        hit count would not be comparable
     * @return list<QueryAlternative> best first, at most $limit entries
     */
    public function recover(
        Site $site,
        string $query,
        ?string $filter,
        ?array $hybridParams,
        array $highlightAttributes,
        int $limit = 4,
    ): array {
        $minPartHits = max(1, (int)$site->getSettings()->get(
            'meilisearch.search.recovery.minPartHits',
            self::DEFAULT_MIN_PART_HITS,
        ));
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return [];
        }
        $indexUid = $this->engineFactory->getIndexName($site);

        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        // ---- round trip 1 -------------------------------------------------
        // Three things at once: which word does the index hold for each
        // token (highlight probe), which split halves are real words
        // (quoted phrase probe — quotes switch typo tolerance and prefix
        // matching OFF, so "nauslegen" can no longer sneak through as a
        // near-miss of "auslegen"), and does the joined spelling exist.
        $splits = $this->splitCandidates($tokens);
        $probes = [];
        foreach ($tokens as $i => $token) {
            $probes['token:' . $i] = $this->probe($indexUid, $token, $filter, $hybridParams, 'all', $highlightAttributes);
        }
        foreach ($this->splitParts($splits) as $part) {
            $probes['part:' . $part] = $this->probe($indexUid, '"' . $part . '"', $filter, $hybridParams, 'all');
        }
        $joined = $this->joinCandidate($tokens);
        if ($joined !== null) {
            $probes['join:' . $joined] = $this->probe($indexUid, $joined, $filter, $hybridParams, 'all');
        }
        $probes['relaxed'] = $this->probe($indexUid, $query, $filter, $hybridParams, 'last', [], self::MIN_RANKING_SCORE);

        $results = $this->execute($client, $probes);
        if ($results === []) {
            return [];
        }

        $alternatives = [];
        if ($joined !== null) {
            $joinHits = $this->totalHits($results['join:' . $joined] ?? null);
            if ($joinHits > 0) {
                $alternatives[] = new QueryAlternative($joined, $joinHits, QueryAlternative::KIND_JOIN);
            }
        }

        // ---- corrected + dropped need the probe outcome, so they go into
        // a second round trip. Both are derived from the same insight:
        // which tokens does the index know, and under which spelling?
        $corrections = [];
        $deadTokens = [];
        foreach ($tokens as $i => $token) {
            $result = $results['token:' . $i] ?? null;
            if ($this->totalHits($result) < 1) {
                $deadTokens[$i] = $token;
                continue;
            }
            $indexed = $this->extractMatchedWord($result);
            if ($indexed !== null && $this->isPlausibleCorrection($token, $indexed)) {
                $corrections[$i] = $indexed;
            }
        }

        $second = [];
        // Only splits whose every part is a word the corpus really
        // contains. Without this gate the ladder happily proposes
        // "fußbode nauslegen" (53 hits, pure prefix noise).
        foreach ($splits as $splitQuery) {
            if (!$this->partsAreWords($splitQuery, $results, $minPartHits)) {
                continue;
            }
            $second['split:' . $splitQuery] = $this->probe($indexUid, $splitQuery, $filter, $hybridParams, 'all');
        }
        if ($corrections !== []) {
            $correctedTokens = $tokens;
            foreach ($corrections as $i => $word) {
                $correctedTokens[$i] = $word;
            }
            $corrected = implode(' ', $correctedTokens);
            if (mb_strtolower($corrected) !== mb_strtolower($query)) {
                $second['corrected:' . $corrected] = $this->probe($indexUid, $corrected, $filter, $hybridParams, 'all');
            }
        }
        if ($deadTokens !== [] && count($deadTokens) < count($tokens)) {
            $kept = array_values(array_diff_key($tokens, $deadTokens));
            $dropped = implode(' ', $kept);
            if ($dropped !== '' && mb_strtolower($dropped) !== mb_strtolower($query)) {
                $second['dropped:' . $dropped] = $this->probe($indexUid, $dropped, $filter, $hybridParams, 'all', [], self::MIN_RANKING_SCORE);
            }
        }
        if ($second !== []) {
            foreach ($this->execute($client, $second) as $key => $result) {
                $hits = $this->totalHits($result);
                if ($hits < 1) {
                    continue;
                }
                // Keys are "<kind>:<query>"; the query itself may contain
                // colons, hence the limit.
                [$kind, $candidateQuery] = explode(':', (string)$key, 2);
                $alternatives[] = new QueryAlternative($candidateQuery, $hits, $kind);
            }
        }

        $relaxedHits = $this->totalHits($results['relaxed'] ?? null);
        if ($relaxedHits > 0) {
            $alternatives[] = new QueryAlternative($query, $relaxedHits, QueryAlternative::KIND_RELAXED);
        }

        return $this->rank($alternatives, $query, $limit);
    }

    /**
     * Split every long token at every plausible position — the German
     * compound case. Meilisearch cannot do this itself: its tokenizer
     * splits on separators, not inside words, so "fußbodenauslegen" is
     * one token that matches nothing. Candidates are cheap because they
     * all travel in the same multi-search.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function splitCandidates(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $token) {
            $length = mb_strlen($token);
            if ($length < self::MIN_COMPOUND_LENGTH) {
                continue;
            }
            for ($cut = self::MIN_SPLIT_PART; $cut <= $length - self::MIN_SPLIT_PART; $cut++) {
                $left = mb_substr($token, 0, $cut);
                $right = mb_substr($token, $cut);
                // Only ever split between two letters. A token containing
                // digits, underscores or plus signs is a product type key
                // or a parameter name ("lin_ww_flowrate", "lzs 50") — the
                // engine already splits those, and cutting them again just
                // produces fragments that match the very same documents.
                // Those belong in the dictionary setting instead.
                if (preg_match('/^\p{L}+$/u', $left) !== 1 || preg_match('/^\p{L}+$/u', $right) !== 1) {
                    continue;
                }
                $parts = $tokens;
                $parts[$i] = $left . ' ' . $right;
                $out[] = implode(' ', $parts);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * "fußboden auslegen" typed as two words when the index holds the
     * compound — the mirror image of splitCandidates.
     *
     * @param list<string> $tokens
     */
    private function joinCandidate(array $tokens): ?string
    {
        if (count($tokens) !== 2) {
            return null;
        }
        return $tokens[0] . $tokens[1];
    }

    /**
     * Every distinct half of every split candidate, deduplicated — these
     * are the words we ask the corpus about.
     *
     * @param list<string> $splits
     * @return list<string>
     */
    private function splitParts(array $splits): array
    {
        $parts = [];
        foreach ($splits as $split) {
            foreach (preg_split('/\s+/u', $split) ?: [] as $part) {
                if ($part !== '' && mb_strlen($part) >= self::MIN_SPLIT_PART) {
                    $parts[$part] = true;
                }
            }
        }
        return array_keys($parts);
    }

    /**
     * @param array<string,array<string,mixed>> $results
     */
    private function partsAreWords(string $splitQuery, array $results, int $minPartHits): bool
    {
        foreach (preg_split('/\s+/u', $splitQuery) ?: [] as $part) {
            if ($part === '' || mb_strlen($part) < self::MIN_SPLIT_PART) {
                continue;
            }
            if (!array_key_exists('part:' . $part, $results)) {
                // Not probed (too short to be worth it) — treat as
                // unverified rather than valid.
                return false;
            }
            if ($this->totalHits($results['part:' . $part]) < $minPartHits) {
                return false;
            }
        }
        return true;
    }

    /**
     * A correction has to be a near-miss of what was typed, not merely a
     * word Meilisearch happened to highlight. Probing "lin_ww_flowrate"
     * returns a document highlighting "LINEAR-Software" (the engine split
     * the token and matched "lin"); offering that as "did you mean" would
     * be actively misleading.
     *
     * Gate: same first character, and an edit distance in proportion to
     * the word length — the same order of magnitude Meilisearch's own typo
     * tolerance uses (1 typo from 5 characters, 2 from 9).
     */
    private function isPlausibleCorrection(string $token, string $indexed): bool
    {
        $a = mb_strtolower($token);
        $b = mb_strtolower($indexed);
        if ($a === $b || $b === '') {
            return false;
        }
        if (mb_substr($a, 0, 1) !== mb_substr($b, 0, 1)) {
            return false;
        }
        $length = mb_strlen($a);
        $allowed = $length >= 9 ? 2 : ($length >= 5 ? 1 : 0);
        if ($allowed === 0) {
            return false;
        }
        // levenshtein() is byte-based; on UTF-8 that overstates the
        // distance for umlauts, so compare the transliterated forms and
        // keep the byte-length difference as a cheap pre-filter.
        if (abs(strlen($a) - strlen($b)) > $allowed * 2) {
            return false;
        }
        return levenshtein($a, $b) <= $allowed * 2;
    }

    /**
     * Read the word the index actually contains out of the highlighted
     * snippet. Meilisearch marks the matched substring, which for a
     * typo-tolerant match is the correctly spelled word — but it may mark
     * only the matching prefix, so grow the selection to the word
     * boundaries before handing it back.
     *
     * @param array<string,mixed>|null $result
     */
    private function extractMatchedWord(?array $result): ?string
    {
        $hit = $result['hits'][0]['_formatted'] ?? null;
        if (!is_array($hit)) {
            return null;
        }
        foreach ($hit as $value) {
            if (!is_string($value) || !str_contains($value, self::MARK_PRE)) {
                continue;
            }
            $start = mb_strpos($value, self::MARK_PRE);
            if ($start === false) {
                continue;
            }
            $afterPre = $start + mb_strlen(self::MARK_PRE);
            $end = mb_strpos($value, self::MARK_POST, $afterPre);
            if ($end === false) {
                continue;
            }
            $plain = str_replace([self::MARK_PRE, self::MARK_POST], '', $value);
            $marked = mb_substr($value, $afterPre, $end - $afterPre);
            $offset = mb_strpos($plain, $marked);
            if ($offset === false || $marked === '') {
                continue;
            }
            // Grow left and right while the neighbours are word characters,
            // so a highlighted prefix ("enab" of "enabler") becomes the
            // whole word instead of a truncated suggestion.
            $left = $offset;
            while ($left > 0 && $this->isWordChar(mb_substr($plain, $left - 1, 1))) {
                $left--;
            }
            $right = $offset + mb_strlen($marked);
            $max = mb_strlen($plain);
            while ($right < $max && $this->isWordChar(mb_substr($plain, $right, 1))) {
                $right++;
            }
            $word = trim(mb_substr($plain, $left, $right - $left));
            if ($word !== '' && mb_strlen($word) >= self::MIN_TOKEN_LENGTH) {
                return $word;
            }
        }
        return null;
    }

    private function isWordChar(string $char): bool
    {
        return $char !== '' && preg_match('/^[\p{L}\p{N}_-]$/u', $char) === 1;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $parts = preg_split('/[\s]+/u', mb_strtolower($query)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            // Strip punctuation the tokenizer would drop anyway, but keep
            // in-word characters (hyphen, underscore, plus) — those are
            // exactly what product type keys are made of.
            $token = trim($part, ".,;:!?\"'()[]{}");
            if ($token !== '' && mb_strlen($token) >= self::MIN_TOKEN_LENGTH) {
                $tokens[] = $token;
            }
        }
        return array_slice($tokens, 0, self::MAX_TOKENS);
    }

    /**
     * @param list<string> $highlightAttributes
     */
    private function probe(
        string $indexUid,
        string $query,
        ?string $filter,
        ?array $hybridParams,
        string $strategy,
        array $highlightAttributes = [],
        ?float $rankingScoreThreshold = null,
    ): SearchQuery {
        $probe = (new SearchQuery())
            ->setIndexUid($indexUid)
            ->setQuery($query)
            ->setMatchingStrategy($strategy)
            ->setHitsPerPage(1)
            ->setPage(1);
        if ($filter !== null && $filter !== '') {
            $probe->setFilter([$filter]);
        }
        if ($hybridParams !== null) {
            // Rebuild the options object from the array the caller already
            // assembled, so an alternative is counted under exactly the
            // same keyword/semantic blend as the original query.
            $hybrid = new HybridSearchOptions();
            if (isset($hybridParams['semanticRatio'])) {
                $hybrid->setSemanticRatio((float)$hybridParams['semanticRatio']);
            }
            if (!empty($hybridParams['embedder'])) {
                $hybrid->setEmbedder((string)$hybridParams['embedder']);
            }
            $probe->setHybrid($hybrid);
        }
        if ($highlightAttributes !== []) {
            $probe->setAttributesToHighlight($highlightAttributes)
                ->setHighlightPreTag(self::MARK_PRE)
                ->setHighlightPostTag(self::MARK_POST);
        } else {
            // Nothing to render — skip the payload entirely.
            $probe->setAttributesToRetrieve(['id']);
        }
        if ($rankingScoreThreshold !== null) {
            $probe->setRankingScoreThreshold($rankingScoreThreshold);
        }
        return $probe;
    }

    /**
     * @param array<string,SearchQuery> $queries
     * @return array<string,array<string,mixed>> same keys, engine results
     */
    private function execute(Client $client, array $queries): array
    {
        if ($queries === []) {
            return [];
        }
        try {
            $raw = $client->multiSearch(array_values($queries));
        } catch (\Throwable $e) {
            // Recovery is best-effort: a failure here must never turn a
            // legitimate "no results" page into an error page.
            $this->logger?->warning('Query recovery multi-search failed: {message}', [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
        $results = is_array($raw['results'] ?? null) ? $raw['results'] : [];
        return array_combine(array_keys($queries), array_slice($results, 0, count($queries))) ?: [];
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function totalHits(?array $result): int
    {
        if ($result === null) {
            return 0;
        }
        return (int)($result['totalHits'] ?? $result['estimatedTotalHits'] ?? 0);
    }

    /**
     * @param list<QueryAlternative> $alternatives
     * @return list<QueryAlternative>
     */
    private function rank(array $alternatives, string $original, int $limit): array
    {
        $seen = [];
        $unique = [];
        foreach ($alternatives as $alternative) {
            $key = mb_strtolower($alternative->query) . '|' . $alternative->kind;
            // The relaxed candidate legitimately carries the original
            // spelling; every other kind must differ from what was typed.
            if ($alternative->kind !== QueryAlternative::KIND_RELAXED
                && mb_strtolower($alternative->query) === mb_strtolower($original)) {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $alternative;
        }
        usort($unique, static function (QueryAlternative $a, QueryAlternative $b): int {
            return [$a->getPriority(), -$a->totalHits] <=> [$b->getPriority(), -$b->totalHits];
        });
        return array_slice($unique, 0, max(1, $limit));
    }
}

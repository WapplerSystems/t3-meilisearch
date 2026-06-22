<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;
use WapplerSystems\Meilisearch\Event\BeforeSearchEvent;

/**
 * Strips configured stop-words from incoming search queries.
 *
 * Why this exists: Meilisearch's `stopWords` index setting only filters
 * tokens that were ALREADY stop-words at the time a document was indexed
 * — adding stop-words later does not retroactively re-tokenise existing
 * docs, so natural-language questions ("Wie exportiere ich Daten für
 * DÄMMWERK?") still match on every "wie"/"ich"/"für" hit in the corpus
 * and dilute the actual content match. Cheapest workaround: cut the
 * stop-words from the query CLIENT-SIDE before it reaches Meilisearch.
 * That gives the engine the same query a knowledgeable user would have
 * typed ("exportiere Daten DÄMMWERK") and the relevant doc wins.
 *
 * Stop-words come from the standard meilisearch.defaults.stopWords site
 * setting, so a single edit configures both the index-side filter (for
 * future indexings) and the client-side query rewrite (for now).
 *
 * No-op when:
 *  - the event has no Site (defensive — shouldn't happen since v…)
 *  - the site has no stop-words configured
 *  - the query is empty (the listing-everything case)
 *  - stripping would leave an empty query (preserves the original so
 *    "wie ?" doesn't become "" → "match everything")
 */
final class QueryStopWordStripper
{
    public function __construct(
        private readonly SearchConfigurationProvider $configProvider,
    ) {}

    #[AsEventListener('ws_meilisearch/strip-query-stopwords')]
    public function __invoke(BeforeSearchEvent $event): void
    {
        if ($event->site === null || trim($event->query) === '') {
            return;
        }
        // Callers can opt out by setting `stripStopWords => false` in
        // the search options. RAG retrieval uses this — the stripped
        // form "gebe Lizenz frei" provides too few context tokens for
        // multi-word synonyms ("gebe frei" → "freigeben") to expand
        // correctly. Meilisearch's own stop-words handling does fine on
        // the full natural-language question.
        if (($event->options['stripStopWords'] ?? true) === false) {
            return;
        }
        // Per-call stop-word override — RAG retrieval supplies its own
        // narrower list to avoid stripping brand / domain tokens
        // ("linear", "building", "freigeben") that the global index
        // stopWords carry for FE-search tuning. When unset, fall back
        // to the index-wide list.
        $override = $event->options['stopWords'] ?? null;
        $stopWords = is_array($override)
            ? array_values(array_filter(array_map('strval', $override), static fn($w) => $w !== ''))
            : $this->configProvider->indexSettings($event->site)->stopWords;
        if ($stopWords === []) {
            return;
        }
        // Case-insensitive match — site settings list lowercase forms,
        // visitor queries may capitalise the first word.
        $stopWordsLower = array_flip(array_map('mb_strtolower', $stopWords));

        $tokens = preg_split('/(\s+|[?!.,;:])/u', $event->query, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return;
        }
        $kept = [];
        foreach ($tokens as $token) {
            $lower = mb_strtolower(trim($token));
            // Keep separators (whitespace + punctuation) untouched. The
            // explicit check on alphanumeric prefix avoids stripping
            // ", " between words which would fuse tokens like
            // "DÄMMWERK,exportieren".
            if (!preg_match('/^\p{L}|\p{N}/u', $token)) {
                $kept[] = $token;
                continue;
            }
            if (isset($stopWordsLower[$lower])) {
                continue;
            }
            $kept[] = $token;
        }
        $stripped = trim(preg_replace('/\s+/u', ' ', implode('', $kept)) ?? '');
        // Don't kill the query entirely — if every token was a stop-word
        // ("wie ?") we'd return "" which Meilisearch reads as "list all
        // documents". Keep the original in that pathological case.
        if ($stripped === '') {
            return;
        }
        $event->query = $stripped;
    }
}
<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Analytics;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Event\AfterSearchEvent;

/**
 * Persists each non-trivial FE search into tx_wsmeilisearch_search_log so
 * the BE analytics tab can aggregate top queries, zero-result queries,
 * and hybrid-vs-keyword usage over time.
 *
 * Privacy posture: stores ONLY {site, language, query, count, source,
 * hybrid, timestamp} plus the QUERY CONTEXT {facet filters, subtree
 * scope, matching strategy, recovery outcome}. No IPs, no session ids,
 * no user agents — the data is aggregable but contains no PII, so the
 * table is safe to keep around indefinitely (a retention sweep is
 * offered as scheduler task but not required for compliance).
 *
 * The context columns exist because without them the zero-result panel
 * lies: the same query returns 138 hits site-wide and 0 inside a KB
 * subtree, and both were logged as a bare "0 results". Filters and
 * scope are server-built values, not user text, so they add no PII.
 *
 * Gating: opt-in per site via meilisearch.analytics.enabled = true.
 * Silent no-op for sites that haven't opted in.
 *
 * Filtering applied before the write:
 *   • too-short queries (< meilisearch.analytics.minQueryLength) are
 *     dropped — every keystroke through the suggest dropdown would
 *     otherwise generate 5+ rows of noise.
 *   • CLI-context searches (no SiteLanguage) skip the language
 *     column rather than guess.
 *
 * Listens to AfterSearchEvent so the result count is already known —
 * BeforeSearchEvent fires too early to log "did this query return
 * anything?".
 */
final class SearchAnalyticsLogger implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TABLE = 'tx_wsmeilisearch_search_log';
    private const MAX_QUERY_LENGTH = 255;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    #[AsEventListener('ws_meilisearch/search-analytics-logger')]
    public function __invoke(AfterSearchEvent $event): void
    {
        $site = $event->site;
        if (!$site instanceof Site) {
            return;
        }
        // RAG retrieval runs through SearchService too and would
        // otherwise land here as a phantom 'search' row (plus one row
        // per fallback-ladder retry). The RagAnalyticsLogger writes a
        // single clean 'rag' row from AfterRagAnswerEvent instead, so
        // skip the internal retrieval search entirely.
        if (!empty($event->options['__skipAnalytics'])) {
            return;
        }
        $settings = $site->getSettings();
        if ((bool)$settings->get('meilisearch.analytics.enabled', false) !== true) {
            return;
        }

        $query = $this->normalizeQuery($event->query);
        $minLength = max(1, (int)$settings->get('meilisearch.analytics.minQueryLength', 2));
        if (mb_strlen($query) < $minLength) {
            return;
        }

        $languageId = 0;
        if (isset($event->options['__languageId'])) {
            $languageId = (int)$event->options['__languageId'];
        } else {
            $requestLanguage = $GLOBALS['TYPO3_REQUEST']?->getAttribute('language') ?? null;
            if ($requestLanguage instanceof SiteLanguage) {
                $languageId = $requestLanguage->getLanguageId();
            }
        }

        $source = (string)($event->options['__analyticsSource'] ?? 'search');
        $hybrid = (bool)($event->options['hybrid'] ?? false);

        try {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
                'crdate' => time(),
                'site_identifier' => substr($site->getIdentifier(), 0, 64),
                'language_id' => $languageId,
                'query' => $query,
                'result_count' => (int)$event->result->totalHits,
                'source' => substr($source, 0, 32),
                'hybrid' => $hybrid ? 1 : 0,
                'filters' => $this->encodeFilters($event->options['filters'] ?? []),
                'scope_uid' => max(0, (int)($event->options['__scopeUid'] ?? 0)),
                'matching_strategy' => substr((string)($event->options['matchingStrategy'] ?? ''), 0, 16),
                'recovery' => substr((string)($event->options['__recovery'] ?? ''), 0, 16),
                'alternatives' => $this->encodeAlternatives($event->options['__alternatives'] ?? []),
            ]);
        } catch (\Throwable $e) {
            // Never break a search just because analytics couldn't
            // write. Schema-not-applied during a deploy window is
            // the most realistic failure mode.
            $this->logger?->info(
                'Search analytics insert skipped: {msg}',
                ['msg' => $e->getMessage()],
            );
        }
    }

    /**
     * The visitor's facet selection as compact JSON. Server-built entries
     * are dropped: `__rawFilters` holds access-control and scope
     * expressions (recorded separately, and noise in an aggregation), and
     * `language` duplicates the language_id column.
     */
    private function encodeFilters(mixed $filters): string
    {
        if (!is_array($filters) || $filters === []) {
            return '';
        }
        $clean = [];
        foreach ($filters as $key => $value) {
            $name = (string)$key;
            if (str_starts_with($name, '__') || $name === 'language' || $name === 'contentLanguage') {
                continue;
            }
            $clean[$name] = is_array($value) ? array_values(array_map('strval', $value)) : (string)$value;
        }
        if ($clean === []) {
            return '';
        }
        ksort($clean);
        return substr((string)json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1024);
    }

    /**
     * Offered-but-not-applied alternatives as "kind:query" pairs. These are
     * the editorial gold: a query that only worked after a compound split
     * or a typo fix is a synonym waiting to be written down.
     */
    private function encodeAlternatives(mixed $alternatives): string
    {
        if (!is_array($alternatives) || $alternatives === []) {
            return '';
        }
        $flat = implode(' | ', array_map('strval', array_slice($alternatives, 0, 5)));
        return mb_substr($flat, 0, 500);
    }

    private function normalizeQuery(string $query): string
    {
        // Lowercase + collapse whitespace so "Foo  Bar" and "foo bar"
        // aggregate as the same row in the BE top-queries panel.
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($query))) ?? '';
        if (mb_strlen($normalized) > self::MAX_QUERY_LENGTH) {
            $normalized = mb_substr($normalized, 0, self::MAX_QUERY_LENGTH);
        }
        return $normalized;
    }
}

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
 * hybrid, timestamp}. No IPs, no session ids, no user agents — the
 * data is aggregable but contains no PII, so the table is safe to
 * keep around indefinitely (a retention sweep is offered as
 * scheduler task but not required for compliance).
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

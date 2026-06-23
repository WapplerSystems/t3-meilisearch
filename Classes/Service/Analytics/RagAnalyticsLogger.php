<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Analytics;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use WapplerSystems\Meilisearch\Event\AfterRagAnswerEvent;

/**
 * Persists each answered RAG turn into tx_wsmeilisearch_search_log with
 * source='rag' so the BE analytics tab can track RAG usage alongside
 * keyword search: question volume, status mix (ok / no_context /
 * failed), and "answered but cited nothing" turns worth reviewing.
 *
 * Privacy posture mirrors {@see SearchAnalyticsLogger}: stores ONLY
 * {site, language, question, source-count, status, cited-count,
 * hybrid, timestamp}. The generated answer text is deliberately NOT
 * stored — the question is user free-text (same as a search query,
 * which the table already keeps), but the LLM answer is not, keeping
 * the table PII-light and safe to retain.
 *
 * Gating: opt-in per site via meilisearch.analytics.enabled = true
 * (shared master switch with search analytics).
 *
 * Context resolution: AfterRagAnswerEvent carries only the question +
 * RagAnswer, so site/language are read from the active request — which
 * also naturally scopes logging to the frontend (BE rag-test runs and
 * CLI have no FE 'site' attribute and are skipped).
 */
final class RagAnalyticsLogger implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const TABLE = 'tx_wsmeilisearch_search_log';
    private const MAX_QUERY_LENGTH = 255;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    #[AsEventListener('ws_meilisearch/rag-analytics-logger')]
    public function __invoke(AfterRagAnswerEvent $event): void
    {
        // 'disabled' means RAG is switched off for the site — there is
        // no real turn to record. Everything else (ok / no_context /
        // failed) is signal worth keeping.
        if ($event->answer->status === 'disabled') {
            return;
        }

        // Prefer the site carried on the event (reliable inside the
        // streaming middleware); fall back to the active request for any
        // dispatch path that predates the enriched event.
        $site = $event->site;
        if (!$site instanceof Site) {
            $site = $GLOBALS['TYPO3_REQUEST']?->getAttribute('site');
        }
        if (!$site instanceof Site) {
            return;
        }
        $settings = $site->getSettings();
        if ((bool)$settings->get('meilisearch.analytics.enabled', false) !== true) {
            return;
        }

        $query = $this->normalizeQuery($event->question);
        $minLength = max(1, (int)$settings->get('meilisearch.analytics.minQueryLength', 2));
        if (mb_strlen($query) < $minLength) {
            return;
        }

        $languageId = $event->languageId;
        if ($languageId === null) {
            $language = $GLOBALS['TYPO3_REQUEST']?->getAttribute('language');
            $languageId = $language instanceof SiteLanguage ? $language->getLanguageId() : 0;
        }

        $hybrid = (bool)$settings->get('meilisearch.rag.useHybrid', true);

        try {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
                'crdate' => time(),
                'site_identifier' => substr($site->getIdentifier(), 0, 64),
                'language_id' => $languageId,
                'query' => $query,
                // How many context hits were sent to the LLM.
                'result_count' => count($event->answer->sources),
                'source' => 'rag',
                'hybrid' => $hybrid ? 1 : 0,
                'status' => substr($event->answer->status, 0, 16),
                // How many of those the model actually cited. 0 with
                // status=ok flags a low-confidence answer.
                'cited_count' => count($event->answer->citedIds),
            ]);
        } catch (\Throwable $e) {
            // Never break a RAG answer because analytics couldn't write.
            $this->logger?->info(
                'RAG analytics insert skipped: {msg}',
                ['msg' => $e->getMessage()],
            );
        }
    }

    private function normalizeQuery(string $query): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($query))) ?? '';
        if (mb_strlen($normalized) > self::MAX_QUERY_LENGTH) {
            $normalized = mb_substr($normalized, 0, self::MAX_QUERY_LENGTH);
        }
        return $normalized;
    }
}
<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\RagTest;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\RagService;

/**
 * Runs Q&A regression tests stored in tx_wsmeilisearch_ragtest. For
 * each test row:
 *
 *   1. ask RagService the question (same pipeline as the FE plugin)
 *   2. embed the expected and the actual answer via the site's
 *      configured embedder
 *   3. compute cosine similarity between the two vectors
 *   4. compare against the per-row similarity_threshold → pass / fail
 *   5. persist the outcome back onto the row
 *
 * Decoupled from CLI / scheduler so both can drive it without
 * duplicating logic.
 */
final class RagTestRunner
{
    public const TABLE = 'tx_wsmeilisearch_ragtest';
    public const HISTORY_TABLE = 'tx_wsmeilisearch_ragtest_run';
    /**
     * Per-test cap on stored history rows. Rolling-window pruning runs
     * after every insert so the table stays bounded without operator
     * intervention. 100 ≈ ~3 months of nightly runs — enough for
     * sparkline trend recognition, small enough that the DELETE stays
     * a single index-driven query.
     */
    public const HISTORY_KEEP = 100;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly RagService $ragService,
        private readonly EmbeddingClientRegistry $embeddingClients,
    ) {}

    /**
     * Run every enabled test, optionally filtered by site identifier.
     * Persists outcomes onto each row.
     *
     * @return list<array{uid:int, title:string, result:RagTestResult}>
     */
    public function runAll(?string $siteFilter = null): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new \TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction());
        $qb->getRestrictions()->add(new \TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction());
        $qb->select('uid', 'title', 'question', 'expected_answer', 'similarity_threshold', 'site_identifier')
            ->from(self::TABLE);
        if ($siteFilter !== null && $siteFilter !== '') {
            $qb->where($qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteFilter)));
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $result = $this->runRow($row);
            $this->persist((int)$row['uid'], $result);
            $out[] = [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'result' => $result,
            ];
        }
        return $out;
    }

    /**
     * Run a single test by uid (BE "Run now" button). Loads the row,
     * scores it, persists the outcome. Throws when the uid points to
     * a non-existent / deleted row — the caller surfaces that as a
     * flash message.
     */
    public function runOne(int $uid): RagTestResult
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new \TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction());
        $row = $qb->select('uid', 'title', 'question', 'expected_answer', 'similarity_threshold', 'site_identifier')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            throw new \RuntimeException(sprintf('No test row with uid=%d', $uid));
        }
        $result = $this->runRow($row);
        $this->persist((int)$row['uid'], $result);
        return $result;
    }

    /**
     * @param array<string,mixed> $row test definition row
     */
    private function runRow(array $row): RagTestResult
    {
        $question = trim((string)$row['question']);
        $expected = trim((string)$row['expected_answer']);
        $threshold = (float)$row['similarity_threshold'];
        if ($question === '' || $expected === '') {
            return RagTestResult::error('question or expected_answer is empty');
        }

        try {
            $site = $this->resolveSite((string)$row['site_identifier']);
        } catch (\Throwable $e) {
            return RagTestResult::error($e->getMessage());
        }

        // Ask the RAG pipeline. Bypass conversation memory by hitting
        // the service directly with a single-shot question — we want
        // each test deterministic, no carry-over between rows.
        try {
            // temperature 0: the score is a cosine similarity against a
            // fixed expected answer, so a sampled answer turns the whole
            // suite into a coin flip. Measured on the June history of these
            // ten tests at the production default of 0.2, the same question
            // with unchanged code scored anywhere from 0.227 to 0.927 —
            // a spread far wider than any regression it is meant to catch.
            // Production keeps its own temperature; only the harness is
            // pinned.
            $answer = $this->ragService->ask($site, $question, ['temperature' => 0.0]);
        } catch (\Throwable $e) {
            return RagTestResult::error('RAG ask failed: ' . $e->getMessage());
        }
        // A clarifying question is a defined outcome, not a breakdown:
        // report it as such so the summary keeps error counts meaningful.
        if ($answer->status === 'clarify') {
            // RagAnswer carries the clarifying question in `answer`; there
            // is no separate property for it.
            return RagTestResult::clarify(trim((string)$answer->answer));
        }
        // RagAnswer uses literal status strings (no constants): 'ok',
        // 'failed', 'disabled', 'no_context'. Only 'ok' carries an
        // answer we can score.
        if ($answer->status !== 'ok') {
            return RagTestResult::error(sprintf('RAG status=%s, error=%s', $answer->status, (string)$answer->error), (string)$answer->answer);
        }
        $actual = trim($answer->answer);
        if ($actual === '') {
            return RagTestResult::error('RAG returned empty answer', '');
        }

        try {
            $client = $this->embeddingClients->forSite($site);
            $expectedVec = $client->embed($site, $expected);
            $actualVec = $client->embed($site, $actual);
        } catch (\Throwable $e) {
            return RagTestResult::error('Embedding call failed: ' . $e->getMessage(), $actual);
        }

        $score = $this->cosineSimilarity($expectedVec, $actualVec);
        if ($score === null) {
            return RagTestResult::error('Cannot compute cosine — vectors empty or zero-norm', $actual);
        }

        return $score >= $threshold
            ? RagTestResult::pass($score, $actual)
            : RagTestResult::fail($score, $actual);
    }

    /**
     * Cosine = dot(a, b) / (|a| * |b|). Returns null when either norm
     * is zero (can't normalise → comparison is undefined).
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineSimilarity(array $a, array $b): ?float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return null;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return null;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    private function resolveSite(string $explicit): Site
    {
        if ($explicit !== '') {
            return $this->siteFinder->getSiteByIdentifier($explicit);
        }
        // Empty test config → first site with a RAG provider set.
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (trim((string)$site->getSettings()->get('meilisearch.rag.provider', '')) !== '') {
                return $site;
            }
        }
        throw new \RuntimeException('No site has meilisearch.rag.provider configured — set site_identifier on the test row');
    }

    private function persist(int $uid, RagTestResult $result): void
    {
        $now = time();
        // Update the test row's last_* snapshot (drives the BE List
        // module badges + the BE-tab status column).
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, [
            'last_status' => $result->status,
            'last_score' => $result->score,
            'last_actual_answer' => $result->actualAnswer,
            'last_error' => $result->error,
            'last_run_at' => $now,
            'tstamp' => $now,
        ], ['uid' => $uid], [
            ParameterType::STRING,
            ParameterType::STRING, // DECIMAL accepts string; null stays null
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
        ]);
        // Append one history row (rolling per-test log used by the
        // sparkline + trend table).
        $historyConn = $this->connectionPool->getConnectionForTable(self::HISTORY_TABLE);
        $historyConn->insert(self::HISTORY_TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'test_uid' => $uid,
            'status' => $result->status,
            'score' => $result->score,
            'actual_answer' => $result->actualAnswer,
            'error_message' => $result->error,
        ]);
        $this->pruneHistory($uid);
    }

    /**
     * Roll the history table: keep only the most recent
     * {@see HISTORY_KEEP} rows per test_uid, drop the rest. Cheap on
     * the test_recent index; runs after every insert so we never
     * accumulate more than HISTORY_KEEP per test (with one transient
     * +1 between INSERT and DELETE).
     */
    private function pruneHistory(int $testUid): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::HISTORY_TABLE);
        $cutoffRow = $qb->select('crdate')
            ->from(self::HISTORY_TABLE)
            ->where($qb->expr()->eq('test_uid', $qb->createNamedParameter($testUid, ParameterType::INTEGER)))
            ->orderBy('crdate', 'DESC')
            ->setFirstResult(self::HISTORY_KEEP)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($cutoffRow === false) {
            return; // fewer than HISTORY_KEEP rows for this test, nothing to prune
        }
        $del = $this->connectionPool->getQueryBuilderForTable(self::HISTORY_TABLE);
        $del->delete(self::HISTORY_TABLE)
            ->where(
                $del->expr()->eq('test_uid', $del->createNamedParameter($testUid, ParameterType::INTEGER)),
                $del->expr()->lte('crdate', $del->createNamedParameter((int)$cutoffRow['crdate'], ParameterType::INTEGER)),
            )
            ->executeStatement();
    }

    /**
     * Fetch the last N (score, status, crdate) for a test, oldest
     * first. Used by the BE tab to render the sparkline.
     *
     * @return list<array{crdate:int, status:string, score:float|null}>
     */
    public function historyFor(int $testUid, int $limit = self::HISTORY_KEEP): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::HISTORY_TABLE);
        $rows = $qb->select('crdate', 'status', 'score')
            ->from(self::HISTORY_TABLE)
            ->where($qb->expr()->eq('test_uid', $qb->createNamedParameter($testUid, ParameterType::INTEGER)))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
        // Reverse to oldest-first for chart rendering.
        $rows = array_reverse($rows);
        return array_map(static fn (array $r): array => [
            'crdate' => (int)$r['crdate'],
            'status' => (string)$r['status'],
            'score' => $r['score'] !== null ? (float)$r['score'] : null,
        ], $rows);
    }
}

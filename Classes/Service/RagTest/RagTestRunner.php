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
            $answer = $this->ragService->ask($site, $question);
        } catch (\Throwable $e) {
            return RagTestResult::error('RAG ask failed: ' . $e->getMessage());
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
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(self::TABLE, [
            'last_status' => $result->status,
            'last_score' => $result->score,
            'last_actual_answer' => $result->actualAnswer,
            'last_error' => $result->error,
            'last_run_at' => time(),
            'tstamp' => time(),
        ], ['uid' => $uid], [
            ParameterType::STRING,
            ParameterType::STRING, // DECIMAL accepts string; null stays null
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
        ]);
    }
}

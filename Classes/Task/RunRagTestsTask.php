<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Task;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use WapplerSystems\Meilisearch\Service\RagTest\RagTestResult;
use WapplerSystems\Meilisearch\Service\RagTest\RagTestRunner;

/**
 * Periodic RAG regression run. Same engine as the
 * ws_meilisearch:run-rag-tests CLI — every enabled
 * tx_wsmeilisearch_ragtest row gets asked, scored against its
 * per-row similarity_threshold, and the outcome persists back onto
 * the row.
 *
 * Task return value semantics for the scheduler:
 *   - true    no failed tests
 *   - false   at least one test FAILed (real quality regression) —
 *             scheduler logs the run as failed so monitoring picks
 *             it up. ERROR-only runs still return true because the
 *             failure mode is infrastructure, not regression; the
 *             test row carries the error detail for triage.
 *
 * Reuses tx_wsmeilisearch_site_identifier from the existing
 * FullReindexTask TCA — same column on tx_scheduler_task, no schema
 * update needed.
 */
final class RunRagTestsTask extends AbstractTask
{
    /**
     * Empty = run every enabled test regardless of site_identifier.
     */
    public string $tx_wsmeilisearch_site_identifier = '';

    public function execute(): bool
    {
        // Scheduler runs outside the DI container — instantiate via
        // GeneralUtility::makeInstance which honours service config.
        $runner = GeneralUtility::makeInstance(RagTestRunner::class);

        $siteFilter = $this->tx_wsmeilisearch_site_identifier !== ''
            ? $this->tx_wsmeilisearch_site_identifier
            : null;
        $results = $runner->runAll($siteFilter);

        $passes = 0;
        $fails = 0;
        $errors = 0;
        foreach ($results as $entry) {
            /** @var RagTestResult $r */
            $r = $entry['result'];
            match ($r->status) {
                RagTestResult::PASS  => $passes++,
                RagTestResult::FAIL  => $fails++,
                RagTestResult::ERROR => $errors++,
                default              => null,
            };
        }

        $this->logger?->info('Meilisearch RAG regression run: {pass} passed, {fail} failed, {err} errored (of {total})', [
            'pass' => $passes,
            'fail' => $fails,
            'err' => $errors,
            'total' => count($results),
            'site' => $this->tx_wsmeilisearch_site_identifier ?: '(all)',
        ]);

        // Only quality regressions flip the scheduler to "failed".
        // Infrastructure errors are logged per-row but don't poison
        // the task status — that'd page on every transient network
        // hiccup.
        return $fails === 0;
    }

    public function setTaskParameters(array $parameters): void
    {
        $this->tx_wsmeilisearch_site_identifier = (string)($parameters['tx_wsmeilisearch_site_identifier'] ?? '');
    }

    public function getAdditionalInformation(): string
    {
        return 'site: ' . ($this->tx_wsmeilisearch_site_identifier !== '' ? $this->tx_wsmeilisearch_site_identifier : '(all)');
    }
}

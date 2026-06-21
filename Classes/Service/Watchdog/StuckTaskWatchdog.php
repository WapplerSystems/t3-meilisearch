<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Watchdog;

use Meilisearch\Client;
use Meilisearch\Contracts\CancelTasksQuery;
use Meilisearch\Contracts\TasksQuery;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Detects + recovers from stuck Meilisearch tasks.
 *
 * Motivation: when the configured embedder backend rate-limits the index
 * (Infomaniak's free tier was observed to throw HTTP 429 against a 51k-doc
 * push), Meilisearch keeps an `documentAdditionOrUpdate` task in
 * `processing` state and retries indefinitely. The reindex CLI returns
 * long before the task settles; the operator never notices until a search
 * silently misses vectors hours later.
 *
 * This watchdog runs on a cron and:
 *   1. Lists enqueued / processing tasks for every Meilisearch-configured site.
 *   2. Flags tasks older than `meilisearch.watchdog.stuckThresholdMinutes`
 *      (default 30) as stuck.
 *   3. Cancels them via `POST /tasks/cancel?uids=…`.
 *   4. Optionally resets the embedder
 *      (`meilisearch.watchdog.resetEmbedderOnStuck`) so a wedged embedder
 *      doesn't immediately re-stuck the next batch — keyword search keeps
 *      working in the meantime.
 *   5. Emails `meilisearch.watchdog.recipient` with the cancelled set.
 *
 * Idempotent: tasks that don't exist anymore are silently skipped.
 * Returns a structured report so the CLI can decide its exit code.
 */
final class StuckTaskWatchdog implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SearchEngineFactory $engineFactory,
    ) {}

    /**
     * @return list<WatchdogReport>
     */
    public function run(?string $siteFilter = null, bool $dryRun = false): array
    {
        $sites = $siteFilter !== null
            ? [$this->siteFinder->getSiteByIdentifier($siteFilter)]
            : $this->siteFinder->getAllSites();

        $reports = [];
        foreach ($sites as $site) {
            $client = $this->engineFactory->createClientForSite($site);
            if ($client === null) {
                continue; // site has no meilisearch.url
            }
            $reports[] = $this->checkSite($site, $client, $dryRun);
        }
        return $reports;
    }

    private function checkSite(Site $site, Client $client, bool $dryRun): WatchdogReport
    {
        $settings = $site->getSettings();
        $thresholdMin = max(1, (int)$settings->get('meilisearch.watchdog.stuckThresholdMinutes', 30));
        $recipient = trim((string)$settings->get('meilisearch.watchdog.recipient', ''));
        if ($recipient === '') {
            // Fall back to the existing quota recipient so operators don't
            // need to configure two notification addresses if they only
            // want one inbox for the AI-related warnings.
            $recipient = trim((string)$settings->get('meilisearch.quota.recipient', ''));
        }
        $resetEmbedder = (bool)$settings->get('meilisearch.watchdog.resetEmbedderOnStuck', false);

        $indexName = $this->engineFactory->getIndexName($site);
        $stuck = $this->findStuckTasks($client, $indexName, $thresholdMin);
        if ($stuck === []) {
            return new WatchdogReport($site->getIdentifier(), 0, [], $recipient, false, $dryRun);
        }

        $cancelledIds = [];
        if (!$dryRun) {
            $cancelledIds = $this->cancelTasks($client, array_column($stuck, 'uid'));
            if ($resetEmbedder) {
                $this->resetEmbedder($client, $indexName);
            }
        }
        $report = new WatchdogReport(
            site: $site->getIdentifier(),
            stuckCount: count($stuck),
            stuckTasks: $stuck,
            recipient: $recipient,
            embedderReset: $resetEmbedder && !$dryRun,
            dryRun: $dryRun,
        );
        if (!$dryRun && $recipient !== '') {
            $report->emailSent = $this->sendNotification($report);
        }
        return $report;
    }

    /**
     * @return list<array{uid:int,type:string,status:string,enqueuedAt:string,startedAt:?string,ageMinutes:int}>
     */
    private function findStuckTasks(Client $client, string $indexName, int $thresholdMin): array
    {
        // Only `processing` is meaningfully "stuck" — `enqueued` tasks are
        // just waiting their turn behind the current batch (large reindex
        // runs legitimately queue tens of thousands of doc-add tasks),
        // and the API returns newest-first with no server-side sort by
        // age, so a 100-result window would miss an actually-hung
        // settingsUpdate buried under 50k legitimate enqueues. Meilisearch
        // processes a handful of tasks concurrently, so `processing` list
        // is always small — checking that catches every real hang.
        $cutoff = time() - ($thresholdMin * 60);
        $query = (new TasksQuery())
            ->setIndexUids([$indexName])
            ->setStatuses(['processing'])
            ->setLimit(50);
        $tasks = $client->getTasks($query)->getResults();

        $stuck = [];
        foreach ($tasks as $task) {
            // Prefer `startedAt` — the relevant age for a processing task
            // is "how long has it been actually running", not "how long
            // ago was it enqueued". Fall back to enqueuedAt for safety
            // when startedAt is missing.
            $started = isset($task['startedAt']) ? (string)$task['startedAt'] : null;
            $enqueued = isset($task['enqueuedAt']) ? (string)$task['enqueuedAt'] : null;
            $referenceTime = $started ?? $enqueued;
            if (!is_string($referenceTime) || $referenceTime === '') {
                continue;
            }
            $ts = strtotime($referenceTime);
            if ($ts === false || $ts > $cutoff) {
                continue;
            }
            $stuck[] = [
                'uid' => (int)($task['uid'] ?? 0),
                'type' => (string)($task['type'] ?? ''),
                'status' => (string)($task['status'] ?? ''),
                'enqueuedAt' => (string)($enqueued ?? ''),
                'startedAt' => $started,
                'ageMinutes' => (int)floor((time() - $ts) / 60),
            ];
        }
        return $stuck;
    }

    /**
     * @param list<int> $uids
     * @return list<int> uids that were accepted by the cancel call
     */
    private function cancelTasks(Client $client, array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        try {
            $client->cancelTasks((new CancelTasksQuery())->setUids($uids));
            return $uids;
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to cancel stuck Meilisearch tasks: {error}', [
                'uids' => $uids,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Clear the embedder so subsequent docs don't immediately re-trigger
     * the same upstream failure. The operator can re-push later via
     * `ws_meilisearch:reindex` (or apply-settings) once the quota / API
     * issue is resolved. Keyword search keeps working in the meantime.
     */
    private function resetEmbedder(Client $client, string $indexName): void
    {
        try {
            $client->index($indexName)->updateEmbedders(['default' => null]);
            $this->logger?->warning('Watchdog reset Meilisearch embedder for {idx} after stuck tasks', [
                'idx' => $indexName,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to reset embedder for {idx}: {error}', [
                'idx' => $indexName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendNotification(WatchdogReport $report): bool
    {
        if ($report->recipient === '') {
            return false;
        }
        $lines = [];
        foreach ($report->stuckTasks as $task) {
            $lines[] = sprintf(
                ' - task #%d (%s) %s — enqueued %s, age %d min',
                $task['uid'],
                $task['type'],
                $task['status'],
                $task['enqueuedAt'],
                $task['ageMinutes'],
            );
        }
        $subject = sprintf(
            '[%s] Meilisearch watchdog: %d stuck task(s) cancelled',
            $report->site,
            $report->stuckCount,
        );
        $body = sprintf(
            "Site: %s\nCancelled stuck Meilisearch tasks:\n\n%s\n\nEmbedder reset: %s\n\nTriage:\n - Check the upstream embedder / LLM provider for rate-limit or auth issues.\n - Once resolved, run `vendor/bin/typo3 ws_meilisearch:reindex %s` to repopulate vectors.\n",
            $report->site,
            implode("\n", $lines),
            $report->embedderReset ? 'yes (re-run ws_meilisearch:reindex after fixing the upstream issue)' : 'no',
            $report->site,
        );
        try {
            $message = new MailMessage();
            $message
                ->to($report->recipient)
                ->subject($subject)
                ->text($body);
            $message->send();
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to send watchdog notification to {to}: {error}', [
                'to' => $report->recipient,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

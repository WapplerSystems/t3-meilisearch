<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Watchdog;

/**
 * Result of a per-site watchdog pass. Returned by `StuckTaskWatchdog::run()`
 * so the CLI / cron can render a table and pick an exit code without
 * re-fetching the data from Meilisearch.
 */
final class WatchdogReport
{
    public bool $emailSent = false;

    /**
     * @param list<array{uid:int,type:string,status:string,enqueuedAt:string,startedAt:?string,ageMinutes:int}> $stuckTasks
     */
    public function __construct(
        public readonly string $site,
        public readonly int $stuckCount,
        public readonly array $stuckTasks,
        public readonly string $recipient,
        public readonly bool $embedderReset,
        public readonly bool $dryRun,
    ) {}
}

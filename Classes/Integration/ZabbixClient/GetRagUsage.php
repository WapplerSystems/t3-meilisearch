<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ZabbixClient;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\Statistics\UsageCounter;
use WapplerSystems\Meilisearch\Service\Rag\Statistics\UsageStatistics;
use WapplerSystems\ZabbixClient\Attribute\MonitoringOperation;
use WapplerSystems\ZabbixClient\Operation\IOperation;
use WapplerSystems\ZabbixClient\OperationResult;

/**
 * zabbix_client operation "GetRagUsage": chat usage of the last hours as one
 * JSON object, meant as master item for dependent items in Zabbix.
 *
 * Parameters: hours (window, default 1), site (identifier, default all sites).
 * Ratios are percent of the window's turns, -1 when the window had none, so a
 * quiet night does not look like a 0 % error rate. daily_usage_pct relates
 * today's accepted questions to meilisearch.rag.protection.perSiteDaily.
 *
 * Registered in Configuration/Services.php only when zabbix_client is
 * installed.
 */
#[MonitoringOperation('GetRagUsage')]
final class GetRagUsage implements IOperation
{
    public function __construct(
        private readonly UsageStatistics $statistics,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function execute(array $parameter = []): OperationResult
    {
        $hours = max(1, min(168, (int)($parameter['hours'] ?? 1)));
        $siteIdentifier = trim((string)($parameter['site'] ?? '')) ?: null;
        try {
            $sites = $siteIdentifier !== null
                ? [$this->siteFinder->getSiteByIdentifier($siteIdentifier)]
                : array_values($this->siteFinder->getAllSites());
        } catch (\Throwable $e) {
            return new OperationResult(false, ['error' => $e->getMessage()]);
        }

        $since = time() - $hours * 3600;
        $today = strtotime('today');
        $summary = $this->statistics->summary($siteIdentifier, $since);
        $counters = $this->statistics->counters($siteIdentifier, $since);
        $todayCounters = $this->statistics->counters($siteIdentifier, $today);
        $todayTurns = $this->statistics->summary($siteIdentifier, $today)['turns'];
        $lastTurn = $this->statistics->lastTurn($siteIdentifier);

        $refused = ['browser' => 0, 'length' => 0, 'client' => 0, 'conversation' => 0, 'site' => 0];
        foreach ($counters as $name => $value) {
            if (str_starts_with($name, UsageCounter::REFUSED_PREFIX)) {
                $refused[substr($name, strlen(UsageCounter::REFUSED_PREFIX))] = $value;
            }
        }

        $dailyLimit = 0;
        $protocol = false;
        $protection = false;
        foreach ($sites as $site) {
            $dailyLimit += $this->dailyLimit($site);
            $protocol = $protocol || (bool)$site->getSettings()->get('meilisearch.rag.protocol.enabled', false);
            $protection = $protection || (bool)$site->getSettings()->get('meilisearch.rag.protection.enabled', false);
        }
        // The counters reset with a cache flush, the protocol does not.
        $todayAccepted = max((int)($todayCounters[UsageCounter::ACCEPTED] ?? 0), $todayTurns);
        $turns = $summary['turns'];
        $unanswered = 0;
        foreach (UsageStatistics::UNANSWERED as $status) {
            $unanswered += $summary['status'][$status] ?? 0;
        }

        return new OperationResult(true, [
            'window_hours' => $hours,
            'turns' => $turns,
            'conversations' => $summary['conversations'],
            'ok' => $summary['status']['ok'] ?? 0,
            'no_context' => $summary['status']['no_context'] ?? 0,
            'failed' => $summary['status']['failed'] ?? 0,
            'clarify' => $summary['status']['clarify'] ?? 0,
            'aborted' => $summary['status']['aborted'] ?? 0,
            'off_topic' => $summary['status']['off_topic'] ?? 0,
            'escalated' => $summary['escalated'],
            'rated_up' => $summary['ratedUp'],
            'rated_down' => $summary['ratedDown'],
            'failed_pct' => $turns > 0 ? round(100 * ($summary['status']['failed'] ?? 0) / $turns, 1) : -1,
            'unanswered_pct' => $turns > 0 ? round(100 * $unanswered / $turns, 1) : -1,
            'withdrawn' => (int)($counters[UsageCounter::WITHDRAWN] ?? 0),
            'accepted' => (int)($counters[UsageCounter::ACCEPTED] ?? 0),
            'refused_total' => array_sum($refused),
            'refused' => $refused,
            'today_accepted' => $todayAccepted,
            'daily_limit' => $dailyLimit,
            'daily_usage_pct' => $dailyLimit > 0 ? round(100 * $todayAccepted / $dailyLimit, 1) : -1,
            'last_turn_age' => $lastTurn > 0 ? time() - $lastTurn : -1,
            'protocol_enabled' => $protocol ? 1 : 0,
            'protection_enabled' => $protection ? 1 : 0,
        ]);
    }

    private function dailyLimit(Site $site): int
    {
        $settings = $site->getSettings();
        if (!(bool)$settings->get('meilisearch.rag.protection.enabled', false)) {
            return 0;
        }

        return max(0, (int)$settings->get('meilisearch.rag.protection.perSiteDaily', 0));
    }
}

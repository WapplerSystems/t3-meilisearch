<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ZabbixClient;

use Meilisearch\Contracts\TasksQuery;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;
use WapplerSystems\ZabbixClient\Attribute\MonitoringOperation;
use WapplerSystems\ZabbixClient\Operation\IOperation;
use WapplerSystems\ZabbixClient\OperationResult;

/**
 * zabbix_client operation "GetMeilisearchHealth": the search engine as the
 * TYPO3 server sees it. Monitoring asks from here because the engine is
 * often reachable only from the web server (IP allow list).
 *
 * Parameter: site (identifier, default the first site with meilisearch.url).
 * healthy is 0 when the engine does not answer; the other figures are -1 then.
 * tasks_failed_24h counts failed tasks of the site's index finished in the
 * last 24 hours — the total keeps growing until someone deletes old tasks.
 *
 * Registered in Configuration/Services.php only when zabbix_client is
 * installed.
 */
#[MonitoringOperation('GetMeilisearchHealth')]
final class GetMeilisearchHealth implements IOperation
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SearchEngineFactory $engineFactory,
    ) {}

    public function execute(array $parameter = []): OperationResult
    {
        $site = $this->resolveSite(trim((string)($parameter['site'] ?? '')));
        if ($site === null) {
            return new OperationResult(false, ['error' => 'no site with meilisearch.url']);
        }
        $result = [
            'site' => $site->getIdentifier(),
            'healthy' => 0,
            'response_ms' => -1,
            'documents' => -1,
            'is_indexing' => -1,
            'tasks_enqueued' => -1,
            'tasks_processing' => -1,
            'tasks_failed_24h' => -1,
            'error' => '',
        ];
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            $result['error'] = 'client could not be created';
            return new OperationResult(true, $result);
        }
        $indexName = $this->engineFactory->getIndexName($site);
        try {
            $start = microtime(true);
            $stats = $client->index($indexName)->stats();
            $result['response_ms'] = (int)round((microtime(true) - $start) * 1000);
            $result['healthy'] = $client->isHealthy() ? 1 : 0;
            $result['documents'] = (int)($stats['numberOfDocuments'] ?? 0);
            $result['is_indexing'] = !empty($stats['isIndexing']) ? 1 : 0;
            $count = static fn(TasksQuery $query): int => $client->getTasks($query->setIndexUids([$indexName])->setLimit(1))->getTotal();
            $result['tasks_enqueued'] = $count((new TasksQuery())->setStatuses(['enqueued']));
            $result['tasks_processing'] = $count((new TasksQuery())->setStatuses(['processing']));
            $result['tasks_failed_24h'] = $count((new TasksQuery())->setStatuses(['failed'])
                ->setAfterFinishedAt(new \DateTimeImmutable('-24 hours')));
        } catch (\Throwable $e) {
            $result['error'] = mb_substr($e->getMessage(), 0, 250);
        }

        return new OperationResult(true, $result);
    }

    private function resolveSite(string $identifier): ?Site
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($identifier !== '' && $site->getIdentifier() !== $identifier) {
                continue;
            }
            if (trim((string)$site->getSettings()->get('meilisearch.url', '')) !== '') {
                return $site;
            }
        }

        return null;
    }
}

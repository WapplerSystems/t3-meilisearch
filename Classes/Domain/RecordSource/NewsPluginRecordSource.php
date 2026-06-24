<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\RecordSource;

use Doctrine\DBAL\ArrayParameterType;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adapter that scopes tx_news indexing to what the site's news plugins
 * actually display, and builds the speaking detail URL per record.
 *
 * Scope is delegated to news' own demand logic (the upstream DemandFactory,
 * see georgringer/news feature/demand-factory) so it can never diverge from
 * the frontend — including category conjunction, sub-categories, archive and
 * time restrictions. Records reachable through no plugin are therefore never
 * indexed.
 *
 * News is a soft dependency: all georgringer/news classes are referenced via
 * makeInstance() behind an isLoaded() guard so this service is harmless when
 * news isn't installed.
 */
final class NewsPluginRecordSource implements PluginRecordSourceInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const NEWS_LIST_CTYPES = ['news_pi1', 'news_newslist'];

    /** @var array<string, array<int, true>> reachable uids per site identifier */
    private array $reachableCache = [];

    /** @var array<string, int> resolved detail page uid per site identifier */
    private array $detailPidCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function getType(): string
    {
        return 'news';
    }

    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('news');
    }

    public function collectReachableUids(Site $site): array
    {
        $id = $site->getIdentifier();
        if (isset($this->reachableCache[$id])) {
            return $this->reachableCache[$id];
        }
        $uids = [];
        if (!$this->isAvailable()) {
            return $this->reachableCache[$id] = $uids;
        }

        $demandFactory = GeneralUtility::makeInstance(\GeorgRinger\News\Domain\Factory\DemandFactory::class);
        $newsRepository = GeneralUtility::makeInstance(\GeorgRinger\News\Domain\Repository\NewsRepository::class);
        $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);

        foreach ($this->findListPlugins($site) as $plugin) {
            $settings = [];
            if (($plugin['pi_flexform'] ?? '') !== '') {
                $parsed = $flexFormService->convertFlexFormContentToArray((string)$plugin['pi_flexform']);
                $settings = is_array($parsed['settings'] ?? null) ? $parsed['settings'] : [];
            }
            // Index the full reachable corpus, not the rendered page: drop
            // pagination so limit/offset can't truncate the set.
            $settings['limit'] = 0;
            $settings['offset'] = 0;

            try {
                $demand = $demandFactory->createDemandObjectFromSettings($settings);
                // respectEnableFields = true → hidden / start / endtime
                // enforced, so unpublished records never enter the index.
                $records = $newsRepository->findDemanded($demand, true);
                foreach ($records as $news) {
                    $uids[(int)$news->getUid()] = true;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning(
                    'News scope resolution failed for plugin {uid}: {msg}',
                    ['uid' => $plugin['uid'] ?? 0, 'msg' => $e->getMessage()],
                );
            }
        }

        return $this->reachableCache[$id] = $uids;
    }

    public function buildUri(Site $site, int $uid, int $languageId): string
    {
        $detailPid = $this->resolveDetailPid($site);
        if ($detailPid === 0) {
            return '';
        }
        try {
            $language = $site->getLanguageById($languageId);
            $uri = $site->getRouter()->generateUri($detailPid, [
                '_language' => $language,
                'tx_news_pi1' => ['controller' => 'News', 'action' => 'detail', 'news' => $uid],
            ]);
            return (string)$uri;
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'News URL generation failed for uid {uid} (lang {lang}): {msg}',
                ['uid' => $uid, 'lang' => $languageId, 'msg' => $e->getMessage()],
            );
            return '';
        }
    }

    /**
     * Active news list plugins whose page belongs to this site.
     *
     * @return list<array{uid:int, pid:int, pi_flexform:string}>
     */
    private function findListPlugins(Site $site): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'pid', 'pi_flexform')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->in('CType', $qb->createNamedParameter(self::NEWS_LIST_CTYPES, ArrayParameterType::STRING)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pid'];
            try {
                if ($this->siteFinder->getSiteByPageId($pid)->getIdentifier() !== $site->getIdentifier()) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $out[] = [
                'uid' => (int)$row['uid'],
                'pid' => $pid,
                'pi_flexform' => (string)($row['pi_flexform'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Detail page = the page the News::detail route enhancer is bound to
     * (limitToPages). This is the authoritative source — far more reliable
     * than the plugin's (often empty) settings.detailPid, and it's the only
     * page on which generateUri yields a speaking URL.
     */
    private function resolveDetailPid(Site $site): int
    {
        $id = $site->getIdentifier();
        if (isset($this->detailPidCache[$id])) {
            return $this->detailPidCache[$id];
        }
        $pid = 0;
        foreach (($site->getConfiguration()['routeEnhancers'] ?? []) as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $isDetail = str_contains((string)($cfg['defaultController'] ?? ''), 'News::detail');
            foreach (($cfg['routes'] ?? []) as $route) {
                if (is_array($route) && str_contains((string)($route['_controller'] ?? ''), 'News::detail')) {
                    $isDetail = true;
                }
            }
            if ($isDetail) {
                $pages = (array)($cfg['limitToPages'] ?? []);
                if ($pages !== []) {
                    $pid = (int)$pages[0];
                    break;
                }
            }
        }
        return $this->detailPidCache[$id] = $pid;
    }
}
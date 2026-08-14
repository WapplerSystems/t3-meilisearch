<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use WapplerSystems\Meilisearch\Domain\RecordSource\PluginRecordSourceInterface;
use WapplerSystems\Meilisearch\Domain\RecordSource\PluginRecordSourceRegistry;
use WapplerSystems\Meilisearch\Service\BoostCalculator;
use WapplerSystems\Meilisearch\Service\HtmlToText;

final class NewsSchemaProvider implements SchemaProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly BoostCalculator $boostCalculator,
        private readonly \WapplerSystems\Meilisearch\Service\LanguageDetector $languageDetector,
        private readonly PluginRecordSourceRegistry $recordSourceRegistry,
        private readonly HtmlToText $htmlToText,
    ) {}

    public function getTable(): string
    {
        return 'tx_news_domain_model_news';
    }

    public function supports(string $table): bool
    {
        return $table === 'tx_news_domain_model_news'
            && ExtensionManagementUtility::isLoaded('news');
    }

    public function buildDocumentId(int $uid): string
    {
        return 'news-' . $uid;
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        // News translations live as separate tx_news_domain_model_news rows
        // with their own uids — one document id per row, same as pages.
        yield $this->buildDocumentId($uid);
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        if (!ExtensionManagementUtility::isLoaded('news')) {
            return;
        }
        $source = $this->newsSource();
        $reachable = $source?->collectReachableUids($site);
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
        $row = $qb->select(...$this->columnsToSelect())
            ->from('tx_news_domain_model_news')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery()
            ->fetchAssociative();
        if ($row !== false) {
            // Scope: only index the record if a news plugin actually makes
            // it reachable. Unreachable records are skipped (eviction of a
            // now-unreachable record is the change listener's job).
            if ($reachable !== null && !$this->isReachable($row, $reachable)) {
                return;
            }
            yield $this->toDocument($row, $site, $source);
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        if (!ExtensionManagementUtility::isLoaded('news')) {
            return;
        }
        $source = $this->newsSource();
        $reachable = $source?->collectReachableUids($site);
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
        $result = $qb->select(...$this->columnsToSelect())
            ->from('tx_news_domain_model_news')
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery();
        $total = 0;
        $indexed = 0;
        while ($row = $result->fetchAssociative()) {
            $total++;
            // Scope: skip records no news plugin would display — this is the
            // safety net against indexing records from folders/categories
            // that were never meant to be public.
            if ($reachable !== null && !$this->isReachable($row, $reachable)) {
                continue;
            }
            $indexed++;
            yield $this->toDocument($row, $site, $source);
        }
        if ($reachable !== null && $indexed === 0 && $total > 0) {
            $this->logger?->warning(
                'News indexing scope matched 0 of {total} records for site {site} — check that a news list plugin exists and the News::detail route enhancer points to a valid page.',
                ['total' => $total, 'site' => $site->getIdentifier()],
            );
        }
    }

    private function newsSource(): ?PluginRecordSourceInterface
    {
        $source = $this->recordSourceRegistry->get('news');
        return ($source !== null && $source->isAvailable()) ? $source : null;
    }

    /**
     * A record is reachable iff itself (default language) or its parent
     * (translation) is in the plugin-derived reachable set.
     *
     * @param array<string,mixed> $row
     * @param array<int,true> $reachable
     */
    private function isReachable(array $row, array $reachable): bool
    {
        $language = (int)($row['sys_language_uid'] ?? 0);
        $parent = $language > 0 ? (int)($row['l10n_parent'] ?? 0) : (int)$row['uid'];
        return isset($reachable[$parent]);
    }

    /**
     * Column list shared by both fetch paths. Kept in one spot so the
     * boost column can be added/removed in one place.
     *
     * @return list<string>
     */
    private function columnsToSelect(): array
    {
        return ['uid', 'pid', 'l10n_parent', 'title', 'teaser', 'bodytext', 'datetime', 'sys_language_uid', 'fe_group', 'tx_wsmeilisearch_boost'];
    }

    public function getAdditionalFields(): array
    {
        return [
            new TextField('teaser', searchable: true),
            new TextField('bodytext', searchable: true),
            new IntegerField('datetime', filterable: true, sortable: true),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toDocument(array $row, Site $site, ?PluginRecordSourceInterface $source = null): array
    {
        $teaser = (string)$row['teaser'];
        $bodytext = $this->htmlToText->convert((string)$row['bodytext']);
        // tx_wsmeilisearch_boost may legitimately be absent during the
        // first deploy before database:updateschema runs — treat that
        // as null so the calculator defaults to neutral.
        $recordBoost = isset($row['tx_wsmeilisearch_boost'])
            ? (int)$row['tx_wsmeilisearch_boost']
            : null;
        return [
            'id' => $this->buildDocumentId((int)$row['uid']),
            'type' => 'news',
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'language' => (int)$row['sys_language_uid'],
            // Speaking detail URL built from the plugin's detail page +
            // tx_news route enhancer; '' when no record source / detail
            // page is resolvable (record still indexed, just without link).
            'uri' => $source?->buildUri($site, (int)$row['uid'], (int)$row['sys_language_uid']) ?? '',
            'title' => (string)$row['title'],
            'teaser' => $teaser,
            'bodytext' => $bodytext,
            'content' => trim($teaser . "\n\n" . $bodytext),
            'datetime' => (int)$row['datetime'],
            // FE-access-control: tx_news_domain_model_news.fe_group is a
            // CSV varchar ('0' / '1,5'); empty/zero → public, otherwise
            // the visitor needs one of these group ids in their context
            // groupIds to see the news item. Filter is applied at search
            // time by the AccessControlFilter service.
            'accessGroups' => self::parseFeGroups((string)($row['fe_group'] ?? '')),
            'boost' => $this->boostCalculator->compositeFor($site, 'news', $recordBoost),
            'contentLanguage' => $this->languageDetector->detect($site, trim($teaser . "\n\n" . $bodytext)),
        ];
    }

    /**
     * Parse a TYPO3 fe_group CSV (e.g. "1,5") into an int[]; treats '' and '0'
     * as "no restriction" (= public). Drops 0 entries (no-restriction marker)
     * so the empty list semantics match across all doc types.
     *
     * @return list<int>
     */
    private static function parseFeGroups(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return [];
        }
        $ids = array_map(
            static fn (string $g): int => (int) trim($g),
            explode(',', $raw),
        );
        return array_values(array_filter($ids, static fn (int $g): bool => $g !== 0));
    }
}
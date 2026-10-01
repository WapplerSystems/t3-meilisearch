<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\EndTimeRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\StartTimeRestriction;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\BoostCalculator;
use WapplerSystems\Meilisearch\Service\LanguageDetector;

/**
 * Indexes hand-written knowledge from tx_wsmeilisearch_knowledge_entry.
 *
 * The documents carry type `knowledge_resource` — the type the rest of the
 * extension already treats as internal grounding: excluded from the FE
 * search and its facets, dropped from the visible sources and citations,
 * included when meilisearch.rag.restrictToKnowledgeResources narrows
 * retrieval. A manual entry is exactly that kind of content, and a type of
 * its own would have had to be added to each of those places (PHP and
 * RagStream.js) — and been forgotten in the next one. `resourceType=manual`
 * and the `knowledge-` id prefix tell the two sources apart.
 *
 * Unlike imported knowledge resources, entries belong to one site: the
 * record's pid lies in that site's page tree (the backend module stores it
 * on the root page).
 *
 * Start/end time are evaluated at indexing time only. An entry that becomes
 * valid later enters the index with the next full reindex or the next save.
 */
final class KnowledgeEntrySchemaProvider implements SchemaProviderInterface
{
    public const TABLE = 'tx_wsmeilisearch_knowledge_entry';

    private const COLUMNS = ['uid', 'pid', 'sys_language_uid', 'title', 'body', 'keywords', 'tx_wsmeilisearch_boost'];

    /** @var array<int,string> pid → site identifier ('' = no site) */
    private array $siteOfPid = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly BoostCalculator $boostCalculator,
        private readonly LanguageDetector $languageDetector,
    ) {}

    public function getTable(): string
    {
        return self::TABLE;
    }

    public function supports(string $table): bool
    {
        return $table === self::TABLE;
    }

    public function buildDocumentId(int $uid): string
    {
        return 'knowledge-' . $uid;
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        // Every language is its own row with its own uid, as with imported
        // knowledge resources.
        yield $this->buildDocumentId($uid);
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        $qb = $this->queryBuilder();
        $row = $qb->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if ($row !== false && $this->belongsTo($row, $site)) {
            yield $this->toDocument($row, $site);
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        $result = $this->queryBuilder()->select(...self::COLUMNS)->from(self::TABLE)->executeQuery();
        while ($row = $result->fetchAssociative()) {
            if ($this->belongsTo($row, $site)) {
                yield $this->toDocument($row, $site);
            }
        }
    }

    public function getAdditionalFields(): array
    {
        // resourceType, parentTitle … are declared by
        // KnowledgeResourceSchemaProvider; both write the same document type.
        return [];
    }

    /**
     * Live-visible rows only: deleted, hidden and outside start/end time are
     * left out, so a hidden entry disappears from the index on save
     * (IndexerService removes a record that yields no document).
     */
    private function queryBuilder(): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction())
            ->add(new StartTimeRestriction())
            ->add(new EndTimeRestriction());

        return $qb;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function belongsTo(array $row, Site $site): bool
    {
        $pid = (int)$row['pid'];
        if (!isset($this->siteOfPid[$pid])) {
            try {
                $this->siteOfPid[$pid] = $this->siteFinder->getSiteByPageId($pid)->getIdentifier();
            } catch (\Throwable) {
                $this->siteOfPid[$pid] = '';
            }
        }

        return $this->siteOfPid[$pid] === $site->getIdentifier();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toDocument(array $row, Site $site): array
    {
        $title = trim((string)$row['title']);
        $body = trim((string)($row['body'] ?? ''));
        $keywords = trim((string)($row['keywords'] ?? ''));
        $content = trim($title . "\n\n" . $body);

        return [
            'id' => $this->buildDocumentId((int)$row['uid']),
            'type' => 'knowledge_resource',
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'language' => (int)$row['sys_language_uid'],
            'title' => $title,
            'subtitle' => '',
            'abstract' => '',
            'keywords' => $keywords,
            'content' => $content,
            // No page to link to: the entry grounds the answer, it is not a
            // destination. Citations of knowledge resources are dropped anyway.
            'uri' => '',
            'resourceType' => 'manual',
            'helpSourcePath' => '',
            'imageUrl' => '',
            'parentTitle' => '',
            'parentUid' => 0,
            'accessGroups' => [],
            'boost' => $this->boostCalculator->compositeFor($site, 'knowledge_resource', (int)$row['tx_wsmeilisearch_boost']),
            'contentLanguage' => $this->languageDetector->detect($site, $content),
        ];
    }
}

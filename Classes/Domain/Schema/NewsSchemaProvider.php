<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use CmsIg\Seal\Schema\Field\IntegerField;
use CmsIg\Seal\Schema\Field\TextField;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class NewsSchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
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

    public function fetchDocument(int $uid, Site $site): ?array
    {
        if (!ExtensionManagementUtility::isLoaded('news')) {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
        $row = $qb->select('uid', 'pid', 'title', 'teaser', 'bodytext', 'datetime', 'sys_language_uid')
            ->from('tx_news_domain_model_news')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \PDO::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $this->toDocument($row);
    }

    public function iterateDocuments(Site $site): iterable
    {
        if (!ExtensionManagementUtility::isLoaded('news')) {
            return;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_news_domain_model_news');
        $result = $qb->select('uid', 'pid', 'title', 'teaser', 'bodytext', 'datetime', 'sys_language_uid')
            ->from('tx_news_domain_model_news')
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery();
        while ($row = $result->fetchAssociative()) {
            yield $this->toDocument($row);
        }
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
    private function toDocument(array $row): array
    {
        return [
            'id' => $this->buildDocumentId((int)$row['uid']),
            'type' => 'news',
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'language' => (int)$row['sys_language_uid'],
            'title' => (string)$row['title'],
            'teaser' => (string)$row['teaser'],
            'bodytext' => strip_tags((string)$row['bodytext']),
            'datetime' => (int)$row['datetime'],
        ];
    }
}
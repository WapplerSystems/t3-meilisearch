<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Schema;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

final class PageSchemaProvider implements SchemaProviderInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTable(): string
    {
        return 'pages';
    }

    public function supports(string $table): bool
    {
        return $table === 'pages';
    }

    public function buildDocumentId(int $uid): string
    {
        return 'pages-' . $uid;
    }

    public function buildDocumentIds(int $uid, Site $site): iterable
    {
        // Page translations are stored as their own pages.uid rows, so each
        // language already has a unique doc id — only one id per page uid.
        yield $this->buildDocumentId($uid);
    }

    public function fetchDocuments(int $uid, Site $site): iterable
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $row = $qb->select('uid', 'pid', 'title', 'subtitle', 'description', 'abstract', 'keywords', 'sys_language_uid', 'doktype')
            ->from('pages')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0)
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row !== false) {
            yield $this->toDocument($row);
        }
    }

    public function iterateDocuments(Site $site): iterable
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $result = $qb->select('uid', 'pid', 'title', 'subtitle', 'description', 'abstract', 'keywords', 'sys_language_uid', 'doktype')
            ->from('pages')
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
                $qb->expr()->in('doktype', [1, 4])
            )
            ->executeQuery();

        while ($row = $result->fetchAssociative()) {
            yield $this->toDocument($row);
        }
    }

    public function getAdditionalFields(): array
    {
        return [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toDocument(array $row): array
    {
        return [
            'id' => $this->buildDocumentId((int)$row['uid']),
            'type' => 'page',
            'uid' => (int)$row['uid'],
            'pid' => (int)$row['pid'],
            'language' => (int)$row['sys_language_uid'],
            'title' => (string)$row['title'],
            'subtitle' => (string)$row['subtitle'],
            'description' => (string)$row['description'],
            'abstract' => (string)$row['abstract'],
            'keywords' => (string)$row['keywords'],
        ];
    }
}
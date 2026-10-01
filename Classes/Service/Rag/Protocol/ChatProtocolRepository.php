<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protocol;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;

/**
 * Storage for chat protocol entries.
 *
 * The table has no deleted/hidden columns, so every query drops the default
 * restrictions. The conversation list must stay portable between
 * MySQL/MariaDB and PostgreSQL, hence plain CASE/SUM aggregates and no
 * GROUP_CONCAT — the first question of each conversation is fetched in a
 * second query instead.
 *
 * Aggregates go through addSelectLiteral(): select() and orderBy() quote
 * their arguments as identifiers and would turn MIN(crdate) into a column
 * name that does not exist.
 */
final class ChatProtocolRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function add(ProtocolEntry $entry): int
    {
        $connection = $this->connectionPool->getConnectionForTable(ProtocolEntry::TABLE);
        $connection->insert(ProtocolEntry::TABLE, $entry->toRow());

        return (int)$connection->lastInsertId(ProtocolEntry::TABLE);
    }

    /**
     * @return list<ProtocolEntry>
     */
    public function findByConversation(string $conversationId, ?string $siteIdentifier = null): array
    {
        if (preg_match(Conversation::ID_PATTERN, $conversationId) !== 1) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(ProtocolEntry::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')
            ->from(ProtocolEntry::TABLE)
            ->where(
                $qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, Connection::PARAM_STR))
            );

        if ($siteIdentifier !== null) {
            $qb->andWhere(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier, Connection::PARAM_STR))
            );
        }

        $qb->orderBy('crdate', 'ASC')
            ->addOrderBy('uid', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): ProtocolEntry => ProtocolEntry::fromRow($row),
            $rows,
        );
    }

    /**
     * @return list<array{conversationId:string,siteIdentifier:string,languageId:int,firstQuestion:string,turns:int,startedAt:int,lastAt:int,escalated:bool,problems:int}>
     */
    public function findConversations(ProtocolFilter $filter, int $limit = 50, int $offset = 0): array
    {
        if ($filter->conversationId !== '' && preg_match(Conversation::ID_PATTERN, $filter->conversationId) !== 1) {
            return [];
        }

        $qb = $this->buildConversationsQuery($filter);
        $qb->orderBy('last_at', 'DESC')
            ->addOrderBy('conversation_id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $rows = $qb->executeQuery()->fetchAllAssociative();
        if ($rows === []) {
            return [];
        }

        $conversationIds = array_values(array_unique(array_map(
            static fn (array $row): string => (string)$row['conversation_id'],
            $rows,
        )));

        $firstQuestions = $this->firstQuestions($conversationIds);

        $result = [];
        foreach ($rows as $row) {
            $conversationId = (string)$row['conversation_id'];
            $result[] = [
                'conversationId' => $conversationId,
                'siteIdentifier' => (string)$row['site_identifier'],
                'languageId' => (int)$row['language_id'],
                'firstQuestion' => $firstQuestions[$conversationId] ?? '',
                'turns' => (int)$row['turns'],
                'startedAt' => (int)$row['started_at'],
                'lastAt' => (int)$row['last_at'],
                'escalated' => (bool)$row['escalated'],
                'problems' => (int)$row['problems'],
            ];
        }

        return $result;
    }

    public function countConversations(ProtocolFilter $filter): int
    {
        if ($filter->conversationId !== '' && preg_match(Conversation::ID_PATTERN, $filter->conversationId) !== 1) {
            return 0;
        }

        $qb = $this->buildConversationsQuery($filter);
        $conversationIds = $qb->executeQuery()->fetchFirstColumn();

        return count($conversationIds);
    }

    public function deleteOlderThan(string $siteIdentifier, int $timestamp): int
    {
        $connection = $this->connectionPool->getConnectionForTable(ProtocolEntry::TABLE);
        $qb = $connection->createQueryBuilder();

        $qb->delete(ProtocolEntry::TABLE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier, Connection::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->lt('crdate', $qb->createNamedParameter($timestamp, Connection::PARAM_INT))
            );

        return (int)$qb->executeStatement();
    }

    private function buildConversationsQuery(ProtocolFilter $filter): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(ProtocolEntry::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->from(ProtocolEntry::TABLE);

        $problemsExpression = $this->problemsExpression($qb);

        $qb->select('conversation_id')->addSelectLiteral(
            'MIN(crdate) AS started_at',
            'MAX(crdate) AS last_at',
            'COUNT(*) AS turns',
            'MAX(escalated) AS escalated',
            'MIN(site_identifier) AS site_identifier',
            'MIN(language_id) AS language_id',
            'SUM(' . $problemsExpression . ') AS problems',
        );

        $qb->groupBy('conversation_id');

        if ($filter->siteIdentifier !== null) {
            $qb->andWhere(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($filter->siteIdentifier, Connection::PARAM_STR))
            );
        }

        if ($filter->languageId !== null) {
            $qb->andWhere(
                $qb->expr()->eq('language_id', $qb->createNamedParameter($filter->languageId, Connection::PARAM_INT))
            );
        }

        if ($filter->conversationId !== '') {
            $qb->andWhere(
                $qb->expr()->eq('conversation_id', $qb->createNamedParameter($filter->conversationId, Connection::PARAM_STR))
            );
        }

        if ($filter->since > 0) {
            $qb->andHaving(
                'MAX(crdate) >= ' . $qb->createNamedParameter($filter->since, Connection::PARAM_INT)
            );
        }

        if ($filter->onlyEscalated) {
            $qb->andHaving(
                'MAX(escalated) = ' . $qb->createNamedParameter(1, Connection::PARAM_INT)
            );
        }

        if ($filter->onlyProblems) {
            $havingProblemsExpression = $this->problemsExpression($qb);
            $qb->andHaving(
                'SUM(' . $havingProblemsExpression . ') > ' . $qb->createNamedParameter(0, Connection::PARAM_INT)
            );
        }

        if (trim($filter->search) !== '') {
            $search = trim($filter->search);
            $likePattern = '%' . $qb->escapeLikeWildcards($search) . '%';
            $questionSearchParam = $qb->createNamedParameter($likePattern, Connection::PARAM_STR);
            $answerSearchParam = $qb->createNamedParameter($likePattern, Connection::PARAM_STR);
            $qb->andHaving(
                'SUM(CASE WHEN question LIKE ' . $questionSearchParam . ' OR answer LIKE ' . $answerSearchParam . ' THEN 1 ELSE 0 END) > 0'
            );
        }

        return $qb;
    }

    private function problemsExpression(QueryBuilder $qb): string
    {
        $statusPlaceholders = [];
        foreach (ProtocolFilter::PROBLEM_STATUSES as $status) {
            $statusPlaceholders[] = $qb->createNamedParameter($status, Connection::PARAM_STR);
        }

        return "CASE WHEN status IN (" . implode(',', $statusPlaceholders) . ") OR (status = 'ok' AND (cited_ids IS NULL OR cited_ids = '' OR cited_ids = '[]')) THEN 1 ELSE 0 END";
    }

    /**
     * @param list<string> $conversationIds
     * @return array<string, string>
     */
    private function firstQuestions(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(ProtocolEntry::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('conversation_id', 'question')
            ->from(ProtocolEntry::TABLE)
            ->where(
                $qb->expr()->in('conversation_id', $qb->createNamedParameter($conversationIds, ArrayParameterType::STRING))
            )
            ->orderBy('crdate', 'ASC')
            ->addOrderBy('uid', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $id = (string)$row['conversation_id'];
            if (!isset($map[$id])) {
                $map[$id] = (string)$row['question'];
            }
        }

        return $map;
    }
}

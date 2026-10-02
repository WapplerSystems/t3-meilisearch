<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;

final class KnowledgeListTool extends AbstractKnowledgeTool
{
    public function getName(): string
    {
        return 'knowledge_list';
    }

    public function getDescription(): string
    {
        return 'Lists site knowledge entries, optionally filtered by language, query, state, limit and offset.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => [
                    'type' => 'string',
                    'description' => 'Filter by site language id or two-letter ISO code ("de", "en").',
                ],
                'query' => [
                    'type' => 'string',
                    'maxLength' => 200,
                    'description' => 'Substring filter for title, body and keywords.',
                ],
                'state' => [
                    'type' => 'string',
                    'enum' => ['all', 'visible', 'hidden'],
                    'default' => 'all',
                    'description' => 'Visibility filter: all, visible (hidden=false) or hidden (hidden=true).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
                    'description' => 'Maximum number of entries to return.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Number of entries to skip.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getScope(): string
    {
        return McpClient::SCOPE_KNOWLEDGE_READ;
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(array $arguments, McpContext $context): array
    {
        $languageId = null;
        if (($arguments['language'] ?? null) !== null) {
            $languageId = $context->language($arguments['language'])->getLanguageId();
        }

        $query = trim((string)($arguments['query'] ?? ''));
        if (mb_strlen($query) > 200) {
            throw new McpToolException('query must be at most 200 characters long.');
        }

        $state = (string)($arguments['state'] ?? 'all');
        if (!in_array($state, ['all', 'visible', 'hidden'], true)) {
            throw new McpToolException('state must be one of "all", "visible", "hidden".');
        }

        $limit = (int)($arguments['limit'] ?? 50);
        if ($limit < 1 || $limit > 200) {
            throw new McpToolException('limit must be between 1 and 200.');
        }

        $offset = (int)($arguments['offset'] ?? 0);
        if ($offset < 0) {
            throw new McpToolException('offset must be 0 or greater.');
        }

        $countBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $countBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $countBuilder->count('uid')->from(self::TABLE);
        $this->applyListFilters($countBuilder, $context, $languageId, $query, $state);

        $dataBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $dataBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $dataBuilder->select('*')->from(self::TABLE);
        $this->applyListFilters($dataBuilder, $context, $languageId, $query, $state);
        $dataBuilder->orderBy('title', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $total = (int)$countBuilder->executeQuery()->fetchOne();
        $rows = $dataBuilder->executeQuery()->fetchAllAssociative();

        return [
            'total' => $total,
            'entries' => array_map(
                fn(array $row): array => $this->entryToArray($row, $context, false),
                $rows
            ),
        ];
    }

    private function applyListFilters(
        QueryBuilder $queryBuilder,
        McpContext $context,
        ?int $languageId,
        string $query,
        string $state
    ): void {
        $queryBuilder->where($queryBuilder->expr()->eq(
            'pid',
            $queryBuilder->createNamedParameter($context->site->getRootPageId(), Connection::PARAM_INT)
        ));

        if ($languageId !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                'sys_language_uid',
                $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
            ));
        }

        if ($query !== '') {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
            $searchLike = '%' . $connection->escapeLikeWildcards($query) . '%';

            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like(
                        'title',
                        $queryBuilder->createNamedParameter($searchLike, Connection::PARAM_STR)
                    ),
                    $queryBuilder->expr()->like(
                        'body',
                        $queryBuilder->createNamedParameter($searchLike, Connection::PARAM_STR)
                    ),
                    $queryBuilder->expr()->like(
                        'keywords',
                        $queryBuilder->createNamedParameter($searchLike, Connection::PARAM_STR)
                    ),
                )
            );
        }

        if ($state === 'visible') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                'hidden',
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
            ));
        } elseif ($state === 'hidden') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                'hidden',
                $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)
            ));
        }
    }
}

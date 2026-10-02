<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\Meilisearch\Domain\Schema\KnowledgeEntrySchemaProvider;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;
use WapplerSystems\Meilisearch\Mcp\McpToolInterface;

abstract class AbstractKnowledgeTool implements McpToolInterface
{
    protected const TABLE = KnowledgeEntrySchemaProvider::TABLE;

    public function __construct(
        protected readonly ConnectionPool $connectionPool,
    ) {}

    abstract public function getName(): string;
    abstract public function getDescription(): string;
    abstract public function getInputSchema(): array;
    abstract public function getScope(): string;
    abstract public function isWriting(): bool;
    abstract public function call(array $arguments, McpContext $context): array;

    protected function findEntry(int $uid, McpContext $context): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($context->site->getRootPageId(), Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new McpToolException(sprintf('No knowledge entry with uid %d on this site.', $uid));
        }

        return $row;
    }

    protected function entryToArray(array $row, McpContext $context, bool $full): array
    {
        $languageId = (int)$row['sys_language_uid'];
        try {
            $languageCode = $context->site->getLanguageById($languageId)->getLocale()->getLanguageCode();
        } catch (\Throwable) {
            $languageCode = '';
        }

        $now = time();
        $hidden = (bool)$row['hidden'];
        $start = (int)$row['starttime'];
        $end = (int)$row['endtime'];

        $entry = [
            'uid' => (int)$row['uid'],
            'title' => (string)$row['title'],
            'languageId' => $languageId,
            'language' => $languageCode,
            'hidden' => $hidden,
            'active' => !$hidden && ($start === 0 || $start <= $now) && ($end === 0 || $end >= $now),
            'validFrom' => $start === 0 ? null : date('c', $start),
            'validUntil' => $end === 0 ? null : date('c', $end),
            'keywords' => (string)($row['keywords'] ?? ''),
            'updatedAt' => date('c', (int)$row['tstamp']),
            'sourceConversation' => (string)($row['source_conversation'] ?? ''),
        ];

        if ($full) {
            $entry['body'] = (string)($row['body'] ?? '');
            $entry['notes'] = (string)($row['notes'] ?? '');
        } else {
            $entry['excerpt'] = mb_substr(trim((string)($row['body'] ?? '')), 0, 200);
        }

        return $entry;
    }

    protected function fieldsFromArguments(array $arguments, McpContext $context, bool $creating): array
    {
        $fields = [];

        if (array_key_exists('title', $arguments)) {
            $title = trim((string)$arguments['title']);
            if ($title === '') {
                throw new McpToolException('title must not be empty.');
            }
            if (mb_strlen($title) > 255) {
                throw new McpToolException('title must be at most 255 characters long.');
            }
            $fields['title'] = $title;
        }

        if (array_key_exists('body', $arguments)) {
            $body = trim((string)$arguments['body']);
            if (mb_strlen($body) > 20000) {
                throw new McpToolException('body must be at most 20000 characters long.');
            }
            $fields['body'] = $body;
        }

        if (array_key_exists('keywords', $arguments)) {
            $fields['keywords'] = trim((string)$arguments['keywords']);
        }

        if (array_key_exists('notes', $arguments)) {
            $fields['notes'] = trim((string)$arguments['notes']);
        }

        if (array_key_exists('hidden', $arguments)) {
            if (!is_bool($arguments['hidden'])) {
                throw new McpToolException('hidden must be a boolean.');
            }
            $fields['hidden'] = $arguments['hidden'] ? 1 : 0;
        }

        if (array_key_exists('validFrom', $arguments)) {
            $fields['starttime'] = $this->parseDateField($arguments['validFrom']);
        }

        if (array_key_exists('validUntil', $arguments)) {
            $fields['endtime'] = $this->parseDateField($arguments['validUntil']);
        }

        if (array_key_exists('sourceConversation', $arguments)) {
            $sourceConversation = strtolower(trim((string)$arguments['sourceConversation']));
            if ($sourceConversation !== '' && preg_match('/^[0-9a-f]{32}$/', $sourceConversation) !== 1) {
                throw new McpToolException('sourceConversation must be a lowercase 32-character hexadecimal id.');
            }
            $fields['source_conversation'] = $sourceConversation;
        }

        if ($creating) {
            $fields['sys_language_uid'] = $context->language($arguments['language'] ?? null)->getLanguageId();
        }

        return $fields;
    }

    protected function writableFieldSchema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'maxLength' => 255,
                'description' => 'Short title of the knowledge entry.',
            ],
            'body' => [
                'type' => 'string',
                'maxLength' => 20000,
                'description' => 'The assistant knowledge text, written as complete sentences in the entry language.',
            ],
            'keywords' => [
                'type' => 'string',
                'description' => 'Comma-separated synonyms or alternative wordings; indexed so the entry is found for phrasings the body does not contain.',
            ],
            'language' => [
                'type' => 'string',
                'description' => 'Language id or two-letter ISO code ("de", "en"). Default is the site default language.',
            ],
            'hidden' => [
                'type' => 'boolean',
                'description' => 'Whether the entry is hidden from the assistant.',
            ],
            'validFrom' => [
                'type' => 'string',
                'description' => 'ISO 8601 date or datetime when the entry becomes valid; an empty string removes the start date.',
            ],
            'validUntil' => [
                'type' => 'string',
                'description' => 'ISO 8601 date or datetime when the entry expires; an empty string removes the end date.',
            ],
            'notes' => [
                'type' => 'string',
                'description' => 'Editor note, never indexed.',
            ],
            'sourceConversation' => [
                'type' => 'string',
                'pattern' => '^[0-9a-f]{32}$',
                'description' => 'Lowercase 32-character hexadecimal conversation id this entry was written for, if any.',
            ],
        ];
    }

    protected function writeData(array $data, array $cmd, McpContext $context): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $cmd, $context->backendUser());

        if ($data !== []) {
            $dataHandler->process_datamap();
        }
        if ($cmd !== []) {
            $dataHandler->process_cmdmap();
        }

        if ($dataHandler->errorLog !== []) {
            throw new McpToolException('TYPO3 refused the change: ' . implode(' ', $dataHandler->errorLog));
        }

        return $dataHandler;
    }

    private function parseDateField(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_string($value)) {
            throw new McpToolException('validFrom/validUntil must be an ISO 8601 date or datetime string, or null.');
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            throw new McpToolException(sprintf(
                'Invalid date/time "%s". Expected ISO 8601, e.g. 2026-01-31 or 2026-01-31T12:00:00+00:00.',
                $value
            ));
        }
    }
}

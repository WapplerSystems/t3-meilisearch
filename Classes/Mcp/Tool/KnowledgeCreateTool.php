<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;

final class KnowledgeCreateTool extends AbstractKnowledgeTool
{
    public function getName(): string
    {
        return 'knowledge_create';
    }

    public function getDescription(): string
    {
        return 'Creates a new site knowledge entry. Write the body as complete sentences in the entry language, as one would explain it to a customer; one topic per entry; put synonyms/alternative wordings into keywords. Check with knowledge_list first whether an entry on the topic exists and update it instead of creating a duplicate.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->writableFieldSchema(),
            'required' => ['title', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function getScope(): string
    {
        return McpClient::SCOPE_KNOWLEDGE_WRITE;
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function call(array $arguments, McpContext $context): array
    {
        if (!isset($arguments['title']) || trim((string)$arguments['title']) === '') {
            throw new McpToolException('title is required.');
        }
        if (!isset($arguments['body']) || trim((string)$arguments['body']) === '') {
            throw new McpToolException('body is required.');
        }

        $fields = $this->fieldsFromArguments($arguments, $context, true);
        $fields['pid'] = $context->site->getRootPageId();

        $dataHandler = $this->writeData(
            [self::TABLE => ['NEW1' => $fields]],
            [],
            $context
        );

        $newUid = (int)($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
        if ($newUid <= 0) {
            throw new McpToolException('TYPO3 did not report the new record uid.');
        }

        $row = $this->findEntry($newUid, $context);

        return [
            'created' => true,
            'entry' => $this->entryToArray($row, $context, true),
        ];
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;

final class KnowledgeUpdateTool extends AbstractKnowledgeTool
{
    public function getName(): string
    {
        return 'knowledge_update';
    }

    public function getDescription(): string
    {
        return 'Updates an existing site knowledge entry by uid. Any writable field except language can be changed; setting hidden=true hides it, hidden=false shows it. At least one field besides uid must be provided.';
    }

    public function getInputSchema(): array
    {
        $properties = $this->writableFieldSchema();
        unset($properties['language']);

        return [
            'type' => 'object',
            'properties' => [
                'uid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The uid of the entry to update.',
                ],
            ] + $properties,
            'required' => ['uid'],
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
        $uid = (int)($arguments['uid'] ?? 0);
        if ($uid <= 0) {
            throw new McpToolException('uid must be a positive integer.');
        }

        $this->findEntry($uid, $context);

        if (array_key_exists('language', $arguments)) {
            throw new McpToolException('The language of an existing entry cannot be changed.');
        }

        $fields = $this->fieldsFromArguments($arguments, $context, false);
        if ($fields === []) {
            throw new McpToolException('Provide at least one field to update besides uid.');
        }

        $this->writeData(
            [self::TABLE => [$uid => $fields]],
            [],
            $context
        );

        $row = $this->findEntry($uid, $context);

        return [
            'updated' => true,
            'entry' => $this->entryToArray($row, $context, true),
        ];
    }
}

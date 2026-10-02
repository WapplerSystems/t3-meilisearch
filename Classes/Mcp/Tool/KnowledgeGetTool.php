<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;

final class KnowledgeGetTool extends AbstractKnowledgeTool
{
    public function getName(): string
    {
        return 'knowledge_get';
    }

    public function getDescription(): string
    {
        return 'Reads one site knowledge entry by uid, including full body and notes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The uid of the knowledge entry.',
                ],
            ],
            'required' => ['uid'],
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
        $uid = (int)($arguments['uid'] ?? 0);
        if ($uid <= 0) {
            throw new McpToolException('uid must be a positive integer.');
        }

        $row = $this->findEntry($uid, $context);

        return [
            'entry' => $this->entryToArray($row, $context, true),
        ];
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;

final class KnowledgeDeleteTool extends AbstractKnowledgeTool
{
    public function getName(): string
    {
        return 'knowledge_delete';
    }

    public function getDescription(): string
    {
        return 'Deletes an existing site knowledge entry by uid. Prefer hiding via knowledge_update when unsure; deletion removes the entry from the assistant immediately.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The uid of the entry to delete.',
                ],
            ],
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

        $this->writeData(
            [],
            [self::TABLE => [$uid => ['delete' => 1]]],
            $context
        );

        return [
            'deleted' => true,
            'uid' => $uid,
        ];
    }
}

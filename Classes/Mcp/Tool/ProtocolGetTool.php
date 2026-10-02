<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;
use WapplerSystems\Meilisearch\Mcp\McpToolInterface;
use WapplerSystems\Meilisearch\Service\Rag\Conversation;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRepository;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ProtocolEntry;

final class ProtocolGetTool implements McpToolInterface
{
    public function __construct(
        private readonly ChatProtocolRepository $repository,
    ) {}

    public function getName(): string
    {
        return 'protocol_get';
    }

    public function getDescription(): string
    {
        return 'Returns one chat conversation with every turn.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conversationId' => ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$'],
            ],
            'required' => ['conversationId'],
            'additionalProperties' => false,
        ];
    }

    public function getScope(): string
    {
        return McpClient::SCOPE_PROTOCOL_READ;
    }

    public function isWriting(): bool
    {
        return false;
    }

    public function call(array $arguments, McpContext $context): array
    {
        $conversationId = $arguments['conversationId'] ?? null;
        if (!is_string($conversationId) || preg_match(Conversation::ID_PATTERN, $conversationId) !== 1) {
            throw new McpToolException('conversationId is required and must be 32 lowercase hex characters.');
        }

        $rows = $this->repository->findByConversation($conversationId, $context->site->getIdentifier());
        if ($rows === []) {
            throw new McpToolException('No conversation with this id on this site.');
        }

        $turns = [];
        foreach ($rows as $row) {
            if ($row instanceof ProtocolEntry) {
                $entry = $row;
            } elseif (is_array($row)) {
                $entry = ProtocolEntry::fromRow($row);
            } else {
                continue;
            }

            $sources = [];
            foreach ($entry->sources as $source) {
                $sourceId = (string)($source['id'] ?? '');
                $sources[] = [
                    'id' => $sourceId,
                    'type' => (string)($source['type'] ?? ''),
                    'title' => (string)($source['title'] ?? ''),
                    'uri' => (string)($source['uri'] ?? ''),
                    'cited' => $entry->wasCited($sourceId),
                ];
            }

            $turns[] = [
                'time' => date('c', $entry->crdate),
                'languageId' => $entry->languageId,
                'channel' => $entry->channel,
                'question' => $entry->question,
                'answer' => $entry->answer,
                'status' => $entry->status,
                'escalated' => $entry->escalated,
                'citedIds' => $entry->citedIds,
                'sources' => $sources,
            ];
        }

        return [
            'conversationId' => $conversationId,
            'turns' => $turns,
        ];
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp\Tool;

use WapplerSystems\Meilisearch\Mcp\McpClient;
use WapplerSystems\Meilisearch\Mcp\McpContext;
use WapplerSystems\Meilisearch\Mcp\McpToolException;
use WapplerSystems\Meilisearch\Mcp\McpToolInterface;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRepository;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ProtocolFilter;

final class ProtocolListTool implements McpToolInterface
{
    public function __construct(
        private readonly ChatProtocolRepository $repository,
    ) {}

    public function getName(): string
    {
        return 'protocol_list';
    }

    public function getDescription(): string
    {
        return 'Lists chat conversations of this site, newest first. Use it to find questions the assistant could not answer (onlyProblems) or where a visitor was offered human contact (onlyEscalated). Each conversation names its origin: channel "web" (visitor in the site chat) or "mcp" (asked through an MCP access, with the access title in "client"). Filter with channel to separate visitors from tests.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'onlyProblems' => ['type' => 'boolean'],
                'onlyEscalated' => ['type' => 'boolean'],
                'search' => ['type' => 'string', 'maxLength' => 200],
                'channel' => ['type' => 'string', 'enum' => ['web', 'mcp'], 'description' => 'Only conversations of this origin.'],
                'client' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Only conversations asked through the MCP access with this title (exact).'],
                'days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 365, 'default' => 30],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ],
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
        $onlyProblems = $this->boolArgument($arguments, 'onlyProblems');
        $onlyEscalated = $this->boolArgument($arguments, 'onlyEscalated');

        $search = '';
        if (array_key_exists('search', $arguments)) {
            if (!is_string($arguments['search'])) {
                throw new McpToolException('search must be a string.');
            }
            $search = trim($arguments['search']);
        }
        if (mb_strlen($search) > 200) {
            throw new McpToolException('search must be at most 200 characters.');
        }

        $channel = '';
        if (array_key_exists('channel', $arguments)) {
            if (!in_array($arguments['channel'], ['web', 'mcp'], true)) {
                throw new McpToolException('channel must be "web" or "mcp".');
            }
            $channel = $arguments['channel'];
        }
        $clientUid = 0;
        if (array_key_exists('client', $arguments)) {
            if (!is_string($arguments['client'])) {
                throw new McpToolException('client must be a string.');
            }
            $clients = $this->repository->findClients($context->site->getIdentifier());
            $clientUid = (int)array_search(trim($arguments['client']), $clients, true);
            if ($clientUid === 0) {
                throw new McpToolException('No MCP access with this title asked on this site. Known: ' . implode(', ', $clients));
            }
            $channel = 'mcp';
        }

        $days = $this->intArgument($arguments, 'days', 30, 0, 365);
        $limit = $this->intArgument($arguments, 'limit', 20, 1, 100);
        $offset = $this->intArgument($arguments, 'offset', 0, 0);

        $filter = new ProtocolFilter(
            siteIdentifier: $context->site->getIdentifier(),
            onlyEscalated: $onlyEscalated,
            onlyProblems: $onlyProblems,
            search: $search,
            since: $days > 0 ? time() - $days * 86400 : 0,
            channel: $channel,
            clientUid: $clientUid,
        );

        $total = $this->repository->countConversations($filter);
        $conversations = $this->repository->findConversations($filter, $limit, $offset);

        $rows = [];
        foreach ($conversations as $conversation) {
            $rows[] = [
                'conversationId' => $conversation['conversationId'],
                'siteIdentifier' => $conversation['siteIdentifier'],
                'languageId' => (int)($conversation['languageId'] ?? 0),
                'firstQuestion' => $conversation['firstQuestion'],
                'turns' => (int)($conversation['turns'] ?? 0),
                'startedAt' => date('c', (int)($conversation['startedAt'] ?? 0)),
                'lastAt' => date('c', (int)($conversation['lastAt'] ?? 0)),
                'escalated' => (bool)($conversation['escalated'] ?? false),
                'problems' => (int)($conversation['problems'] ?? 0),
                'channel' => $conversation['channel'],
                'client' => $conversation['clientTitle'],
            ];
        }

        return [
            'total' => $total,
            'protocolEnabled' => (bool)$context->site->getSettings()->get('meilisearch.rag.protocol.enabled', false),
            'conversations' => $rows,
        ];
    }

    private function boolArgument(array $arguments, string $name): bool
    {
        if (!array_key_exists($name, $arguments)) {
            return false;
        }
        if (!is_bool($arguments[$name])) {
            throw new McpToolException($name . ' must be a boolean.');
        }
        return $arguments[$name];
    }

    private function intArgument(array $arguments, string $name, int $default, int $min, ?int $max = null): int
    {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        $value = $arguments[$name];
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $int = (int)$value;
        } else {
            throw new McpToolException($name . ' must be an integer.');
        }
        if ($int < $min || ($max !== null && $int > $max)) {
            $message = $max !== null
                ? sprintf('%s must be between %d and %d.', $name, $min, $max)
                : sprintf('%s must be at least %d.', $name, $min);
            throw new McpToolException($message);
        }
        return $int;
    }
}

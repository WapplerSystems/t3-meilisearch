<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * JSON-RPC side of the MCP endpoint: initialize, ping, tools/list,
 * tools/call. Stateless — no session id, no server-initiated messages — so
 * every POST is answered on its own and the endpoint scales like any other
 * request. That is all an AI client needs to ask questions and maintain
 * data; resources, prompts and sampling are not offered.
 */
final class McpServer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var array<string,McpToolInterface> */
    private array $tools = [];

    /**
     * @param iterable<McpToolInterface> $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    /**
     * One JSON-RPC message in, the response out — or null for a
     * notification, which gets none.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
     */
    public function handle(array $message, McpContext $context): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return self::error($id, -32600, 'Invalid Request');
        }
        if (!array_key_exists('id', $message)) {
            return null; // notifications/initialized, notifications/cancelled …
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => self::result($id, $this->initialize($params, $context)),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => $this->listTools($context->client)]),
            'tools/call' => $this->callTool($id, $params, $context),
            default => self::error($id, -32601, 'Method not found: ' . $method),
        };
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function initialize(array $params, McpContext $context): array
    {
        $requested = (string)($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'ws-meilisearch',
                'title' => 'Site assistant: ' . $context->site->getIdentifier(),
                'version' => '14.0',
            ],
            'instructions' => 'Tools of the website assistant of site "' . $context->site->getIdentifier() . '". '
                . 'ask_assistant answers from the site\'s documentation like the chat on the website; '
                . 'search_index searches it without an answer; knowledge_* maintain the hand-written knowledge the assistant answers from; '
                . 'protocol_* read the chat protocol to find questions the assistant could not answer.',
        ];
    }

    /**
     * Only the tools this client's scopes allow — a model cannot be tempted
     * by a tool it may not call.
     *
     * @return list<array<string,mixed>>
     */
    private function listTools(McpClient $client): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            if (!$client->allows($tool->getScope())) {
                continue;
            }
            $list[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
                'annotations' => [
                    'readOnlyHint' => !$tool->isWriting(),
                    'destructiveHint' => $tool->isWriting(),
                    'openWorldHint' => false,
                ],
            ];
        }

        return $list;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function callTool(mixed $id, array $params, McpContext $context): array
    {
        $name = (string)($params['name'] ?? '');
        $tool = $this->tools[$name] ?? null;
        if ($tool === null || !$context->client->allows($tool->getScope())) {
            return self::error($id, -32602, 'Unknown tool: ' . $name);
        }
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $data = $tool->call($arguments, $context);
            $isError = false;
        } catch (McpToolException $e) {
            $data = ['error' => $e->getMessage()];
            $isError = true;
        } catch (\Throwable $e) {
            // The message may carry internals (SQL, paths); the model gets a
            // neutral text, the log the details.
            $this->logger?->error('MCP tool {tool} failed: {message}', [
                'tool' => $name,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $data = ['error' => 'Internal error while running the tool.'];
            $isError = true;
        }

        return self::result($id, [
            'content' => [[
                'type' => 'text',
                'text' => (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ]],
            'structuredContent' => $data === [] ? new \stdClass() : $data,
            'isError' => $isError,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string,mixed>
     */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

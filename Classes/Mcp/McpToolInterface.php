<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp;

/**
 * One tool of the MCP endpoint. Implementations are collected through the
 * DI tag ws_meilisearch.mcp_tool (autoconfigured), so another extension can
 * add tools of its own simply by implementing this interface.
 */
interface McpToolInterface
{
    /** Tool name as the client sees it, snake_case, unique. */
    public function getName(): string;

    /** What the tool does and when to use it — read by the model. */
    public function getDescription(): string;

    /**
     * JSON Schema (draft 2020-12 subset) of the arguments, as a PHP array
     * with 'type' => 'object'.
     *
     * @return array<string,mixed>
     */
    public function getInputSchema(): array;

    /** One of McpClient::SCOPE_*; the tool is hidden from clients without it. */
    public function getScope(): string;

    /** True when the tool changes data (MCP annotation readOnlyHint=false). */
    public function isWriting(): bool;

    /**
     * Run the tool. Return structured data; the server serialises it as
     * JSON text content and as structuredContent. Throw McpToolException
     * for errors the model should see.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function call(array $arguments, McpContext $context): array;
}

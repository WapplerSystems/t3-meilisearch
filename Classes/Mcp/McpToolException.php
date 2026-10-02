<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp;

/**
 * A failure the calling model should read and can act on — bad arguments,
 * unknown record, missing permission. Reported as a tool result with
 * isError, not as a JSON-RPC error: MCP reserves protocol errors for
 * protocol problems, and only a tool result reaches the model so it can
 * correct its call.
 */
final class McpToolException extends \RuntimeException
{
}

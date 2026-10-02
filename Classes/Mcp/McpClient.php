<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp;

/**
 * One authenticated MCP access (a row of tx_wsmeilisearch_mcp_token): who
 * it is, what it may do, on which sites, and as which backend user it
 * writes.
 */
final class McpClient
{
    public const SCOPE_ASK = 'ask';
    public const SCOPE_SEARCH = 'search';
    public const SCOPE_KNOWLEDGE_READ = 'knowledge:read';
    public const SCOPE_KNOWLEDGE_WRITE = 'knowledge:write';
    public const SCOPE_PROTOCOL_READ = 'protocol:read';

    public const ALL_SCOPES = [
        self::SCOPE_ASK,
        self::SCOPE_SEARCH,
        self::SCOPE_KNOWLEDGE_READ,
        self::SCOPE_KNOWLEDGE_WRITE,
        self::SCOPE_PROTOCOL_READ,
    ];

    /**
     * @param list<string> $scopes
     * @param list<string> $siteIdentifiers empty = every site
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $title,
        public readonly array $scopes,
        public readonly array $siteIdentifiers,
        /** Backend user the writes run as; 0 = this access cannot write. */
        public readonly int $backendUserUid,
    ) {}

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function allowsSite(string $siteIdentifier): bool
    {
        return $this->siteIdentifiers === [] || in_array($siteIdentifier, $this->siteIdentifiers, true);
    }
}

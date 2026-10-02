<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Mcp;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Storage and verification of MCP access tokens.
 *
 * A token is 160 random bits with a recognisable prefix (wsmcp_…). Only its
 * SHA-256 is stored — a database dump does not hand out working tokens. A
 * plain hash is enough here (no password hashing): the input has full
 * entropy, there is nothing to brute-force.
 */
final class McpTokenRepository
{
    public const TABLE = 'tx_wsmeilisearch_mcp_token';
    private const PREFIX = 'wsmcp_';
    /** last_used is written at most this often per token, not per request. */
    private const LAST_USED_RESOLUTION = 300;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $scopes
     * @param list<string> $sites
     * @return array{uid:int,token:string}
     */
    public function create(string $title, array $scopes, array $sites, int $backendUserUid, int $expires, int $createdBy): array
    {
        $token = self::PREFIX . bin2hex(random_bytes(20));
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'crdate' => time(),
            'tstamp' => time(),
            'title' => mb_substr(trim($title), 0, 255),
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 12),
            'scopes' => implode(',', array_values(array_intersect($scopes, McpClient::ALL_SCOPES))),
            'sites' => implode(',', $sites),
            'be_user' => max(0, $backendUserUid),
            'expires' => max(0, $expires),
            'created_by' => $createdBy,
        ]);

        return ['uid' => (int)$connection->lastInsertId(), 'token' => $token];
    }

    public function authenticate(string $token): ?McpClient
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) > 128) {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('token_hash', $qb->createNamedParameter(hash('sha256', $token))),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }
        $now = time();
        if ((int)$row['expires'] > 0 && (int)$row['expires'] < $now) {
            return null;
        }
        if ($now - (int)$row['last_used'] > self::LAST_USED_RESOLUTION) {
            $this->connectionPool->getConnectionForTable(self::TABLE)
                ->update(self::TABLE, ['last_used' => $now], ['uid' => (int)$row['uid']]);
        }

        return new McpClient(
            uid: (int)$row['uid'],
            title: (string)$row['title'],
            scopes: self::splitList((string)$row['scopes']),
            siteIdentifiers: self::splitList((string)$row['sites']),
            backendUserUid: (int)$row['be_user'],
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findAll(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'crdate', 'title', 'token_prefix', 'scopes', 'sites', 'be_user', 'expires', 'last_used', 'hidden', 'created_by')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('deleted', 0))
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $row['scopes'] = self::splitList((string)$row['scopes']);
            $row['sites'] = self::splitList((string)$row['sites']);
            return $row;
        }, $rows);
    }

    public function setDisabled(int $uid, bool $disabled): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, ['hidden' => $disabled ? 1 : 0, 'tstamp' => time()], ['uid' => $uid]);
    }

    /**
     * Revoking deletes the hash, not only flags the row: a revoked token can
     * never come back, whatever happens to the deleted flag later.
     */
    public function revoke(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, ['deleted' => 1, 'token_hash' => 'revoked-' . $uid, 'tstamp' => time()], ['uid' => $uid]);
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== ''));
    }
}

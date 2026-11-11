<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Statistics;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Hourly counters for chat events the protocol table does not hold: questions
 * the request guard accepted or refused (by reason) and answers the answer
 * guard withdrew. Read by UsageStatistics for monitoring.
 *
 * One cache entry per site and hour in ws_meilisearch_ratelimit, kept three
 * days. The cache is flushed with the system group, so a deploy resets the
 * counters — fine for alerting on the last hours, not a ledger.
 */
final class UsageCounter
{
    public const ACCEPTED = 'accepted';
    public const WITHDRAWN = 'withdrawn';
    public const REFUSED_PREFIX = 'refused.';

    private const CACHE = 'ws_meilisearch_ratelimit';
    private const LIFETIME = 259200;

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    public function increment(string $siteIdentifier, string $counter): void
    {
        try {
            $key = $this->key($siteIdentifier, time());
            $counts = $this->cache()->get($key);
            $counts = is_array($counts) ? $counts : [];
            $counts[$counter] = (int)($counts[$counter] ?? 0) + 1;
            $this->cache()->set($key, $counts, [], self::LIFETIME);
        } catch (\Throwable) {
            // Monitoring must never break a chat answer.
        }
    }

    /**
     * Sum of every counter from the hour containing $since up to now.
     *
     * @return array<string,int>
     */
    public function sumSince(string $siteIdentifier, int $since): array
    {
        $sum = [];
        $hour = $since - $since % 3600;
        for ($t = $hour; $t <= time(); $t += 3600) {
            $counts = $this->cache()->get($this->key($siteIdentifier, $t));
            foreach (is_array($counts) ? $counts : [] as $name => $value) {
                $sum[$name] = ($sum[$name] ?? 0) + (int)$value;
            }
        }

        return $sum;
    }

    private function key(string $siteIdentifier, int $timestamp): string
    {
        return 'usage-' . sha1($siteIdentifier) . '-' . date('YmdH', $timestamp);
    }

    private function cache(): FrontendInterface
    {
        return $this->cache ??= $this->cacheManager->getCache(self::CACHE);
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag\Protection;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\TokenBucket;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Rate limiter state in a TYPO3 cache that honours lifetimes.
 *
 * The core's CachingFrameworkStorage writes to the "ratelimiter" cache, which
 * is a SimpleFileBackend: it ignores the lifetime, so every client hash would
 * stay on disk for good. Here each state expires with its window.
 */
final class ProtectionStorage implements StorageInterface
{
    public function __construct(
        private readonly FrontendInterface $cache,
    ) {}

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->cache->set(
            sha1($limiterState->getId()),
            serialize($limiterState),
            [],
            max(1, (int)$limiterState->getExpirationTime()),
        );
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $item = $this->cache->get(sha1($limiterStateId));
        if (!is_string($item)) {
            return null;
        }
        $value = unserialize($item, ['allowed_classes' => [Window::class, SlidingWindow::class, TokenBucket::class]]);

        return $value instanceof LimiterStateInterface ? $value : null;
    }

    public function delete(string $limiterStateId): void
    {
        $this->cache->remove(sha1($limiterStateId));
    }
}

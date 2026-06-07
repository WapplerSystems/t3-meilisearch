<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Reads per-site live metadata from Meilisearch (doc count + active
 * embedder) and caches the answer for {@see TTL_SECONDS}. Without
 * this every render of the Backend Overview or Diagnostics tab makes
 * one or two synchronous round-trips per site, which becomes painful
 * once a multi-site install has more than a couple of sites.
 *
 * Cache scope is per-site (key = identifier); invalidation is per-site
 * too. Callers must call {@see invalidate()} after they mutate the
 * server-side state (reindex, embedder re-push) so the next render
 * shows fresh numbers.
 *
 * 60s TTL is a deliberate compromise: long enough that the cache
 * actually helps when an admin clicks between tabs, short enough that
 * stale numbers self-heal within a minute even when an invalidation
 * call is forgotten somewhere.
 */
final class IndexMetadataProvider
{
    private const TTL_SECONDS = 60;

    private readonly FrontendInterface $cache;

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        CacheManager $cacheManager,
    ) {
        $this->cache = $cacheManager->getCache('ws_meilisearch_meta');
    }

    /**
     * @return array{
     *     configured: bool,
     *     indexName: string,
     *     docCount: int|null,
     *     embedderActive: bool,
     *     actualEmbedder: array<string,mixed>|null,
     *     error: string|null,
     * }
     */
    public function getMeta(Site $site): array
    {
        $key = $this->cacheKey($site);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $meta = $this->fetchMeta($site);
        $this->cache->set($key, $meta, [], self::TTL_SECONDS);
        return $meta;
    }

    public function invalidate(Site $site): void
    {
        $this->cache->remove($this->cacheKey($site));
    }

    /**
     * @return array{configured:bool,indexName:string,docCount:int|null,embedderActive:bool,actualEmbedder:array<string,mixed>|null,error:string|null}
     */
    private function fetchMeta(Site $site): array
    {
        $settings = $site->getSettings();
        $configured = trim((string)$settings->get('meilisearch.url', '')) !== '';
        $meta = [
            'configured' => $configured,
            'indexName' => '',
            'docCount' => null,
            'embedderActive' => false,
            'actualEmbedder' => null,
            'error' => null,
        ];
        if (!$configured) {
            return $meta;
        }
        $meta['indexName'] = $this->engineFactory->getIndexName($site);

        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return $meta;
        }
        $index = $client->index($meta['indexName']);

        try {
            $stats = $index->stats();
            $meta['docCount'] = (int)($stats['numberOfDocuments'] ?? 0);
        } catch (\Throwable $e) {
            $meta['error'] = $e->getMessage();
        }
        try {
            $embedders = $index->getEmbedders();
            if (is_array($embedders)) {
                $named = $embedders[EmbedderConfigurator::EMBEDDER_NAME] ?? null;
                $meta['embedderActive'] = $named !== null;
                $meta['actualEmbedder'] = is_array($named) ? $named : null;
            }
        } catch (\Throwable) {
            // Leave embedderActive=false; this is informational only.
        }
        return $meta;
    }

    private function cacheKey(Site $site): string
    {
        // Cache identifiers must be alnum + underscore in TYPO3; hash
        // protects against odd characters in user-defined identifiers.
        return 'site_' . hash('xxh3', $site->getIdentifier());
    }
}

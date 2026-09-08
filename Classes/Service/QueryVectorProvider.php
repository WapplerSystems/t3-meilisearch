<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\RagTest\EmbeddingClientRegistry;

/**
 * Embeds the search query in PHP, for the one configuration where Meilisearch
 * cannot do it itself.
 *
 * In precompute mode this extension computes document vectors with its own
 * token bucket and writes them into `_vectors.default`, and
 * EmbedderConfigurator registers the embedder as `userProvided`. That is a
 * document-side contract only: a `userProvided` embedder has no provider
 * credentials, so Meilisearch has no way to turn the visitor's query text into
 * a vector. A hybrid search then runs with an embedder but without anything to
 * compare against, and the semantic half contributes nothing.
 *
 * Measured on the LINEAR corpus (2026-09-08, 12 paraphrase → expected-document
 * pairs): semanticRatio 0, 0.1, 0.3 and 0.5 returned byte-identical results —
 * 7 of 12 — while 1.0 returned nothing at all. The knob was disconnected, and
 * a paraphrase whose compound noun had been split ("Laufzeiten meiner
 * Lizenzen" for "Lizenzlaufzeiten") could not be recovered, because only a
 * vector bridges that and there was no query vector.
 *
 * The alternative was to drop precompute and let Meilisearch embed both sides
 * via the `rest` source. That works — verified against the live engine — but a
 * changed embedder definition makes Meilisearch re-embed every document, which
 * on this index is 68,946 of them through the provider. Supplying just the
 * query vector leaves the existing document vectors untouched; they were
 * produced by the same model, so both sides live in the same vector space.
 *
 * Cost: one provider roundtrip per search, cached (see the
 * ws_meilisearch_query_vector cache) so a repeated query is free. The suggest
 * dropdown is unaffected — it searches with `hybrid => false`, so no
 * keystroke ever reaches a provider.
 *
 * Degrades to keyword search: every failure path returns null, and
 * SearchService then drops the hybrid block rather than sending a search whose
 * semantic half is dead weight.
 */
final class QueryVectorProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Longest query we embed. Beyond this it is not a query any more, and providers charge by token. */
    private const MAX_QUERY_LENGTH = 512;

    private readonly FrontendInterface $cache;

    public function __construct(
        private readonly EmbeddingClientRegistry $clients,
        CacheManager $cacheManager,
    ) {
        $this->cache = $cacheManager->getCache('ws_meilisearch_query_vector');
    }

    /**
     * Whether this site's index needs PHP to supply the query vector.
     *
     * True exactly when the pushed embedder is `userProvided`, which
     * EmbedderConfigurator does for precompute mode — see the class docblock.
     * For every other source Meilisearch holds the credentials and embeds the
     * query itself; sending our own vector there would duplicate the work and
     * risk a different embedding than the documents got.
     */
    public function isNeeded(Site $site): bool
    {
        $settings = $site->getSettings();
        if ((bool)$settings->get('meilisearch.embedder.precompute', false) !== true) {
            return false;
        }

        return (bool)$settings->get('meilisearch.embedder.embedQueries', true);
    }

    /**
     * The query's vector, or null when it is not needed, not possible, or
     * failed. Never throws: a provider outage must cost the visitor a
     * keyword-only result list, not an error page.
     *
     * @return list<float>|null
     */
    public function forQuery(Site $site, string $query): ?array
    {
        $query = trim($query);
        if ($query === '' || !$this->isNeeded($site)) {
            return null;
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        $key = $this->cacheKey($site, $query);
        $cached = $this->cache->get($key);
        if (is_array($cached) && $cached !== []) {
            return array_values(array_map('floatval', $cached));
        }

        try {
            $vector = $this->clients->forSite($site)->embed($site, $query);
        } catch (\Throwable $e) {
            // Quota, timeout, model rename — all the same from here: the
            // search continues without its semantic half.
            $this->logger?->warning('Query embedding failed, falling back to keyword search: {message}', [
                'message' => $e->getMessage(),
                'site' => $site->getIdentifier(),
            ]);
            return null;
        }
        if ($vector === []) {
            $this->logger?->warning('Query embedding returned an empty vector for site {site}', [
                'site' => $site->getIdentifier(),
            ]);
            return null;
        }

        $expected = (int)$site->getSettings()->get('meilisearch.embedder.dimensions', 0);
        if ($expected > 0 && count($vector) !== $expected) {
            // A vector of the wrong width is not a degraded result, it is a
            // different vector space — Meilisearch would reject it, and if it
            // did not, the ranking would be noise. Refuse it loudly.
            $this->logger?->error(
                'Query embedding has {actual} dimensions, index expects {expected} — refusing to search with it',
                ['actual' => count($vector), 'expected' => $expected, 'site' => $site->getIdentifier()],
            );
            return null;
        }

        $this->cache->set($key, $vector, ['ws_meilisearch_query_vector']);

        return $vector;
    }

    /**
     * Everything that decides which vector space a query lands in goes into
     * the key, so switching provider, model or width cannot serve a stale
     * vector from the previous one.
     */
    private function cacheKey(Site $site, string $query): string
    {
        $settings = $site->getSettings();

        return 'qv_' . sha1(implode("\0", [
            $site->getIdentifier(),
            (string)$settings->get('meilisearch.embedder.source', ''),
            (string)$settings->get('meilisearch.embedder.model', ''),
            (string)$settings->get('meilisearch.embedder.dimensions', ''),
            $query,
        ]));
    }
}

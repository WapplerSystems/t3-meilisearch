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
 *
 * ## Why the query is not embedded verbatim
 *
 * Retrieval embedders are asymmetric. The document side gets the passage as
 * it stands — that is what EmbeddingPrecomputer sends, rendered from
 * meilisearch.embedder.documentTemplate — while the query side is trained
 * with an instruction wrapped around the question. Embedding a bare query
 * therefore lands it in a different region than the passages it should match.
 *
 * Measured against the live index (2026-09-08, 12 paraphrase → expected-document
 * pairs, pure vector search, filtered to the same corpus the answer uses):
 *
 *   bare query                                          2 of 12
 *   BGE instruction wrapper (see below)                 8 of 12
 *   "Represent this sentence for searching …" prefix    8 of 12
 *
 * Four times the recall from the wording around the query alone — and 8 of 12
 * is what the entire keyword pipeline with its LLM rewrite reaches, so the two
 * halves finally have something to add to each other.
 */
final class QueryVectorProvider implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Longest query we embed. Beyond this it is not a query any more, and providers charge by token. */
    private const MAX_QUERY_LENGTH = 512;

    /**
     * The instruction BGE retrieval models are trained to see on the query
     * side; passages get none. Used when no queryTemplate is configured and
     * the model name says BGE, because for that family a bare query measurably
     * does not work (see the class docblock) and an operator who configured
     * `bge-multilingual-gemma2` did not ask for a quarter of the recall.
     */
    private const BGE_QUERY_INSTRUCTION = "<instruct>Given a web search query, retrieve relevant passages that answer the query\n<query>{{ query }}";

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

        $text = $this->embedText($site, $query);
        $key = $this->cacheKey($site, $text);
        $cached = $this->cache->get($key);
        if (is_array($cached) && $cached !== []) {
            return array_values(array_map('floatval', $cached));
        }

        try {
            $vector = $this->clients->forSite($site)->embed($site, $text);
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
     * The text actually sent to the provider: the query wrapped in whatever
     * instruction the model expects.
     *
     * `meilisearch.embedder.queryTemplate` wins when set. A template
     * containing `{{ query }}` is filled at that spot; one without it is
     * treated as a prefix and used verbatim, trailing space included, so an
     * operator can write the instruction as a plain string and control the
     * separator. With no template configured, BGE models get the wrapper above
     * and everything else the bare query — a wrapper is model-specific, and
     * guessing one for an unknown model would be worse than sending nothing.
     */
    private function embedText(Site $site, string $query): string
    {
        // NOT trimmed: a prefix template is sent exactly as written, so
        // "Suchanfrage: " keeps the space that separates it from the question.
        // Only the emptiness test ignores whitespace.
        $template = (string)$site->getSettings()->get('meilisearch.embedder.queryTemplate', '');
        if (trim($template) === '') {
            $model = strtolower((string)$site->getSettings()->get('meilisearch.embedder.model', ''));
            if (!str_contains($model, 'bge')) {
                return $query;
            }
            $template = self::BGE_QUERY_INSTRUCTION;
        }

        $filled = preg_replace('/\{\{\s*query\s*\}\}/', $query, $template, -1, $count);
        if ($count > 0 && is_string($filled)) {
            return $filled;
        }

        return $template . $query;
    }

    /**
     * Everything that decides which vector space a query lands in goes into
     * the key, so switching provider, model, width or the query instruction
     * cannot serve a stale vector from the previous one. Keyed on the finished
     * text rather than the raw query for exactly that reason.
     */
    private function cacheKey(Site $site, string $text): string
    {
        $settings = $site->getSettings();

        return 'qv_' . sha1(implode("\0", [
            $site->getIdentifier(),
            (string)$settings->get('meilisearch.embedder.source', ''),
            (string)$settings->get('meilisearch.embedder.model', ''),
            (string)$settings->get('meilisearch.embedder.dimensions', ''),
            $text,
        ]));
    }
}

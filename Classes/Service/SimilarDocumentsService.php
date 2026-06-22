<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Returns "similar documents" for a given doc id by delegating to
 * Meilisearch's native /indexes/{uid}/similar endpoint (v1.12+). The
 * engine ranks similarity off the per-doc embedding vector stored
 * under `_vectors.default`, so this service works out of the box on
 * any site whose embedder is already configured — no extra index
 * setup needed.
 *
 * Typical use cases:
 *   • "You may also like" listing under a help topic page.
 *   • Sidebar "Related content" on a blog post.
 *   • Suggested next-step actions after a RAG answer cites a topic.
 *
 * The service deliberately stays thin — the policy (which types
 * count as "similar", whether the visitor's access groups apply,
 * whether to scope to the active language) lives in callers
 * (middleware, ViewHelper, Fluid partial) so different surfaces
 * can pick different mixes without breaking each other.
 */
final class SimilarDocumentsService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 50;
    private const DEFAULT_ATTRIBUTES = ['id', 'title', 'type', 'uri', 'publicUrl', 'imageUrl', 'abstract', 'language', 'contentLanguage'];

    public function __construct(
        private readonly SearchEngineFactory $engineFactory,
        private readonly AccessControlFilter $accessControlFilter,
    ) {}

    /**
     * Find documents similar to $sourceDocId in $site's index.
     *
     * @param array{
     *     limit?: int,
     *     types?: list<string>,
     *     language?: int|null,
     *     contentLanguageIso?: string|null,
     *     accessRequest?: ServerRequestInterface|null,
     *     attributesToRetrieve?: list<string>,
     *     extraFilters?: list<string>,
     * } $options
     * @return list<array<string,mixed>>
     */
    public function findSimilar(Site $site, string $sourceDocId, array $options = []): array
    {
        if ($sourceDocId === '') {
            return [];
        }
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return [];
        }
        $indexName = $this->engineFactory->getIndexName($site);

        $limit = (int)($options['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0 || $limit > self::MAX_LIMIT) {
            $limit = self::DEFAULT_LIMIT;
        }
        $attributes = $options['attributesToRetrieve'] ?? self::DEFAULT_ATTRIBUTES;

        // Build the filter expression. Each clause goes in as a raw
        // string so callers can combine type / language / extra
        // constraints without us re-implementing AccessControlFilter's
        // expression builder.
        $clauses = [];
        $types = $options['types'] ?? [];
        if (is_array($types) && $types !== []) {
            $escaped = array_map(static fn (string $t): string => '"' . str_replace('"', '\\"', $t) . '"', $types);
            $clauses[] = 'type IN [' . implode(',', $escaped) . ']';
        }
        if (isset($options['language']) && $options['language'] !== null) {
            $clauses[] = 'language = ' . (int)$options['language'];
        }
        $iso = isset($options['contentLanguageIso']) ? trim((string)$options['contentLanguageIso']) : '';
        if ($iso !== '') {
            $escapedIso = str_replace('"', '\\"', $iso);
            $clauses[] = '(contentLanguage = "' . $escapedIso . '" OR contentLanguage IS NULL OR contentLanguage IS EMPTY)';
        }
        foreach ($options['extraFilters'] ?? [] as $extra) {
            if (is_string($extra) && $extra !== '') {
                $clauses[] = $extra;
            }
        }
        // Apply FE access-control on top: same code path the FE search
        // uses, so a logged-out visitor never sees a similar doc they
        // wouldn't see in the full results.
        $accessRequest = $options['accessRequest'] ?? null;
        if ($accessRequest instanceof ServerRequestInterface) {
            $applied = $this->accessControlFilter->applyTo(['__rawFilters' => $clauses], $site, $accessRequest);
            $clauses = $applied['__rawFilters'] ?? $clauses;
        }
        $filter = $clauses === [] ? null : implode(' AND ', array_map(
            static fn (string $c): string => str_starts_with(trim($c), '(') ? $c : '(' . $c . ')',
            $clauses,
        ));

        $payload = [
            'id' => $sourceDocId,
            'embedder' => EmbedderConfigurator::EMBEDDER_NAME,
            'limit' => $limit,
            'attributesToRetrieve' => $attributes,
        ];
        if ($filter !== null) {
            $payload['filter'] = $filter;
        }

        try {
            $response = $client->index($indexName)->getSimilarDocuments($payload);
            $raw = is_array($response) ? $response : $response->toArray();
            $hits = $raw['hits'] ?? [];
            // Defensive — the engine returns the source doc itself in
            // older Meilisearch versions when it shouldn't. Filter it
            // out client-side so callers never have to.
            return array_values(array_filter(
                is_array($hits) ? $hits : [],
                static fn (array $h): bool => (string)($h['id'] ?? '') !== $sourceDocId,
            ));
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'Similar-documents lookup failed for {id} on site {site}: {msg}',
                [
                    'id' => $sourceDocId,
                    'site' => $site->getIdentifier(),
                    'msg' => $e->getMessage(),
                ],
            );
            return [];
        }
    }
}

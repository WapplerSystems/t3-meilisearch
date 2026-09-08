<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Rag;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Service\SearchService;

/**
 * Suggestion buttons taken from documents that exist, instead of from the
 * model's idea of a good next question.
 *
 * SuggestionGenerator writes its followup/refine values out of the answer
 * text and the source titles. That is inherently ungrounded: it does not know
 * the corpus, so it also proposes the adjacent topic nobody documented, and
 * the click lands on "I have no information about that". Probing every
 * generated suggestion (RagService::groundSuggestions) removes those — but
 * removing is all it can do, and a row of buttons that shrinks to nothing is
 * a poorer answer page than one with two buttons that work.
 *
 * This class fills the freed slots from the index. It looks up the siblings
 * of the documents this answer was built on — in a DITA/knowledge-base tree
 * the children of one parent are the related tasks of one topic area — and
 * offers each one under its own title.
 *
 * The title is deliberately both the label and the value: measured on the
 * LINEAR corpus, a document's own title retrieves that document at rank 1
 * ("Lizenzlaufzeiten überprüfen" → the topic of that name), while any
 * paraphrase of it can miss the top five entirely. A button built this way
 * cannot fail retrieval the way a generated one can — the same reason
 * `recommend` never fails, except that this one produces an answered turn
 * rather than sending the reader off to a page.
 *
 * Ranking: the siblings are searched with the answer's own primary title as
 * the query, so the closest relatives come first instead of whatever order
 * the index happens to return.
 */
final class NeighbourSuggestions implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Suggestion type. Distinct from followup/refine so CSS and analytics can tell a grounded button from a generated one. */
    public const TYPE = 'related';

    /** How many parent nodes to pull siblings from. More turns the filter into a tree sweep. */
    private const MAX_PARENTS = 3;

    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    /**
     * @param list<array<string,mixed>> $hits this turn's context documents
     * @param list<string> $citedIds the documents the answer actually used —
     *        their siblings are the relevant ones; the rest of the context is
     *        only a fallback when the answer cited nothing
     * @param array<string,mixed> $searchOptions this turn's retrieval options,
     *        so language, access and corpus filters carry over unchanged
     * @param int $limit how many buttons to return at most (0 → none)
     * @return list<array{type:string,label:string,value:string}>
     */
    public function forContext(
        Site $site,
        array $hits,
        array $citedIds,
        array $searchOptions,
        int $limit,
    ): array {
        if ($limit < 1 || $hits === []) {
            return [];
        }

        $cited = array_values(array_filter(
            $hits,
            static fn (array $hit): bool => in_array((string)($hit['id'] ?? ''), $citedIds, true),
        ));
        $anchors = $cited !== [] ? $cited : $hits;

        $parents = [];
        foreach ($anchors as $hit) {
            $pid = (int)($hit['pid'] ?? 0);
            if ($pid > 0) {
                $parents[$pid] = true;
            }
            if (count($parents) >= self::MAX_PARENTS) {
                break;
            }
        }
        if ($parents === []) {
            return [];
        }

        // Everything already on this page is uninteresting as a "next topic":
        // the context documents by id, and their titles, because the same DITA
        // topic is reused under several ids and would come back as a button
        // that re-asks what was just answered.
        $excludeIds = [];
        $seenTitles = [];
        foreach ($hits as $hit) {
            $id = (string)($hit['id'] ?? '');
            if ($id !== '') {
                $excludeIds[] = $id;
            }
            $title = $this->normalizeTitle((string)($hit['title'] ?? ''));
            if ($title !== '') {
                $seenTitles[$title] = true;
            }
        }

        $query = trim((string)($anchors[0]['title'] ?? ''));
        $options = $this->probeOptions($searchOptions, $parents, $excludeIds, $limit);

        try {
            $result = $this->searchService->search($site, $query, $options);
        } catch (\Throwable $e) {
            $this->logger?->info('Neighbour suggestion lookup failed: {message}', ['message' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach ($result->hits as $hit) {
            $title = trim((string)(((array)$hit)['title'] ?? ''));
            $key = $this->normalizeTitle($title);
            if ($title === '' || $key === '' || isset($seenTitles[$key])) {
                continue;
            }
            $seenTitles[$key] = true;
            $out[] = ['type' => self::TYPE, 'label' => $title, 'value' => $title];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The sibling lookup, built on top of this turn's retrieval options so the
     * language, access-control and corpus filters are identical to the answer's.
     *
     * Deliberate overrides: no hybrid (a title is a precise keyword query, and
     * the vector half would only pull in generic neighbours), no zero-result
     * recovery (a topic area with no other siblings is a valid answer, not a
     * misspelling), and no analytics row (this is not a visitor's search).
     *
     * @param array<string,mixed> $searchOptions
     * @param array<int,true> $parents
     * @param list<string> $excludeIds
     * @return array<string,mixed>
     */
    private function probeOptions(array $searchOptions, array $parents, array $excludeIds, int $limit): array
    {
        $filters = isset($searchOptions['filters']) && is_array($searchOptions['filters'])
            ? $searchOptions['filters']
            : [];
        $filters['pid'] = array_keys($parents);
        if ($excludeIds !== []) {
            // Raw filters are emitted verbatim, so the ids are quoted here.
            // Document ids are of the shape "pages-14026"; the strip is a
            // belt-and-braces guard against a filter injection through an id.
            $quoted = array_map(
                static fn (string $id): string => '"' . str_replace(['"', '\\'], '', $id) . '"',
                $excludeIds,
            );
            $filters['__rawFilters'] = array_merge(
                (array)($filters['__rawFilters'] ?? []),
                ['id NOT IN [' . implode(', ', $quoted) . ']'],
            );
        }

        return array_merge($searchOptions, [
            'filters' => $filters,
            'hybrid' => false,
            'recover' => false,
            '__skipAnalytics' => true,
            // Over-fetch: identical DITA twins and titles already on the page
            // are dropped afterwards, and only what survives becomes a button.
            'perPage' => max(1, $limit * 4),
        ]);
    }

    private function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title));
    }
}

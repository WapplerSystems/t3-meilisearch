<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Configuration;

use TYPO3\CMS\Core\Site\Entity\Site;
use WapplerSystems\Meilisearch\Configuration\Dto\DisplayConfig;
use WapplerSystems\Meilisearch\Configuration\Dto\FacetConfig;
use WapplerSystems\Meilisearch\Configuration\Dto\IndexSettings;

/**
 * Single point of read access for the search/relevance/display configuration.
 *
 * Sources, in this order:
 *  - typed Site Settings (settings.definitions.yaml) for scalars and stringlists
 *  - structural Site Settings (settings.yaml in the Set) for list-of-maps:
 *    facets, display, synonyms, sortOptions
 *  - per-site overrides in config/sites/<id>/settings.yaml apply transparently
 *    through TYPO3's Site\Settings merge — no extra code needed here
 *
 * Callers: ApplyMeilisearchSettingsCommand (pushes IndexSettings to
 * Meilisearch), and — eventually — SearchController / SearchService
 * (consume FacetConfig and DisplayConfig at render time).
 */
final class SearchConfigurationProvider
{
    public function indexSettings(Site $site): IndexSettings
    {
        $s = $site->getSettings();
        return new IndexSettings(
            rankingRules: $this->stringList($this->getNested($site, 'meilisearch.defaults.rankingRules')),
            typoToleranceEnabled:        (bool)($s->get('meilisearch.defaults.typoTolerance.enabled', true)),
            typoMinWordSizeForOneTypo:   (int)$s->get('meilisearch.defaults.typoTolerance.minWordSizeForOneTypo', 5),
            typoMinWordSizeForTwoTypos:  (int)$s->get('meilisearch.defaults.typoTolerance.minWordSizeForTwoTypos', 9),
            typoDisableOnAttributes:     $this->stringList($this->getNested($site, 'meilisearch.defaults.typoTolerance.disableOnAttributes')),
            typoDisableOnWords:           $this->stringList($this->getNested($site, 'meilisearch.defaults.typoTolerance.disableOnWords')),
            typoDisableOnNumbers:        (bool)$s->get('meilisearch.defaults.typoTolerance.disableOnNumbers', false),
            stopWords:                   $this->stringList($this->getNested($site, 'meilisearch.defaults.stopWords')),
            synonyms:                    $this->synonymsMap($this->getNested($site, 'meilisearch.defaults.synonyms')),
            distinctAttribute:           $this->nullableString($s->get('meilisearch.defaults.distinctAttribute')),
            displayedAttributes:         $this->stringList($this->getNested($site, 'meilisearch.defaults.displayedAttributes')) ?: ['*'],
            paginationMaxTotalHits:      (int)$s->get('meilisearch.defaults.pagination.maxTotalHits', 1000),
            facetingMaxValuesPerFacet:   (int)$s->get('meilisearch.defaults.faceting.maxValuesPerFacet', 100),
            facetingSortFacetValuesBy:   (string)$s->get('meilisearch.defaults.faceting.sortFacetValuesBy', 'count'),
            searchCutoffMs:              (int)$s->get('meilisearch.defaults.searchCutoffMs', 0),
        );
    }

    /**
     * @return list<FacetConfig>
     */
    public function facets(Site $site): array
    {
        $raw = $this->getNested($site, 'meilisearch.facets');
        if (!is_array($raw)) {
            return [];
        }
        // TYPO3's Site Settings merge can reverse the original YAML list
        // order when integer keys are reshuffled across sources. Restore
        // declaration order so the rendered facet panel matches what the
        // integrator wrote in settings.yaml.
        ksort($raw, SORT_NUMERIC);
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || empty($entry['attribute'])) {
                continue;
            }
            $out[] = FacetConfig::fromArray($entry);
        }
        return $out;
    }

    /**
     * @return list<string> Attribute names — the bag passed to Meilisearch
     *                      as the `facets` request parameter.
     */
    public function facetAttributes(Site $site): array
    {
        return array_values(array_map(static fn (FacetConfig $f): string => $f->attribute, $this->facets($site)));
    }

    public function display(Site $site, string $type): DisplayConfig
    {
        $raw = $this->getNested($site, 'meilisearch.display');
        if (!is_array($raw)) {
            $raw = [];
        }
        $candidate = $raw[$type] ?? $raw['_default'] ?? [];
        return DisplayConfig::fromArray($type, is_array($candidate) ? $candidate : []);
    }

    /**
     * Union of attribute names declared in any display config's `highlight`
     * list — the bag passed to Meilisearch's attributesToHighlight on every
     * search request, since a single index serves all doctypes. Always
     * non-empty when at least the `_default` entry is configured.
     *
     * @return list<string>
     */
    public function highlightAttributes(Site $site): array
    {
        return $this->collectFieldNames($site, 'highlight');
    }

    /**
     * Union of crop directives across all display configs, normalised to
     * Meilisearch's `<field>:<length>` syntax. Entries that omit `:N` get
     * the site-wide meilisearch.frontend.cropLength appended.
     *
     * @return list<string>
     */
    public function cropAttributes(Site $site): array
    {
        $cropLength = (int)$site->getSettings()->get('meilisearch.frontend.cropLength', 200);
        $raw = $this->collectFieldNames($site, 'crop');
        $normalised = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (!str_contains($entry, ':')) {
                $entry .= ':' . $cropLength;
            }
            if (!isset($seen[$entry])) {
                $seen[$entry] = true;
                $normalised[] = $entry;
            }
        }
        return $normalised;
    }

    public function highlightPreTag(Site $site): string
    {
        return (string)$site->getSettings()->get('meilisearch.frontend.highlightPreTag', '<mark>');
    }

    public function highlightPostTag(Site $site): string
    {
        return (string)$site->getSettings()->get('meilisearch.frontend.highlightPostTag', '</mark>');
    }

    public function cropMarker(Site $site): string
    {
        return (string)$site->getSettings()->get('meilisearch.frontend.cropMarker', '…');
    }

    public function defaultPerPage(Site $site): int
    {
        return max(1, (int)$site->getSettings()->get('meilisearch.frontend.perPage', 20));
    }

    /**
     * Per-type multiplier from meilisearch.boosts.types. Returns 1.0
     * (neutral) when the type isn't listed — that's also the indexer
     * fallback for sites that never configured the map.
     */
    public function typeBoost(Site $site, string $type): float
    {
        if ($type === '') {
            return 1.0;
        }
        $types = $this->getNested($site, 'meilisearch.boosts.types');
        if (!is_array($types) || !isset($types[$type]) || !is_numeric($types[$type])) {
            return 1.0;
        }
        return (float)$types[$type];
    }

    public function isRecordBoostEnabled(Site $site): bool
    {
        return (bool)$site->getSettings()->get('meilisearch.boosts.recordOverrideEnabled', true);
    }

    /**
     * Pre-resolves the partial used to render a hit of the given type.
     * Always returns a non-empty string — the bundled Search/Result
     * partial is the ultimate fallback.
     */
    public function resolveDisplayPartial(Site $site, string $type): string
    {
        $partial = $this->display($site, $type)->partial;
        return $partial !== '' ? $partial : 'Search/Result';
    }

    /**
     * Helper for highlight/crop aggregation: collect a list-typed key
     * across every entry in `meilisearch.display` (incl. `_default`),
     * deduplicating in declaration order.
     *
     * @return list<string>
     */
    private function collectFieldNames(Site $site, string $key): array
    {
        $raw = $this->getNested($site, 'meilisearch.display');
        if (!is_array($raw)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry[$key]) || !is_array($entry[$key])) {
                continue;
            }
            foreach ($entry[$key] as $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                if (!isset($seen[$value])) {
                    $seen[$value] = true;
                    $out[] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * Site Settings expose container paths like `meilisearch.facets` only
     * when they're either a typed identifier or a top-level key — leaf-level
     * scalars are reachable via the flattened map, but a nested array sitting
     * mid-tree returns null. Walk down from the top-level `meilisearch`
     * subtree by hand so the structured YAML defaults in
     * Sets/WsMeilisearch/settings.yaml actually load.
     */
    private function getNested(Site $site, string $path): mixed
    {
        $segments = explode('.', $path);
        if ($segments === []) {
            return null;
        }
        $current = $site->getSettings()->get($segments[0], null);
        array_shift($segments);
        foreach ($segments as $seg) {
            if (!is_array($current) || !array_key_exists($seg, $current)) {
                return null;
            }
            $current = $current[$seg];
        }
        return $current;
    }

    /**
     * @return list<array{value: string, labelKey: string}>
     */
    public function sortOptions(Site $site): array
    {
        $raw = $this->getNested($site, 'meilisearch.sortOptions');
        if (!is_array($raw)) {
            return [];
        }
        ksort($raw, SORT_NUMERIC);
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $out[] = [
                'value'    => (string)($entry['value'] ?? ''),
                'labelKey' => (string)($entry['labelKey'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, list<string>>
     */
    private function synonymsMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $alternatives) {
            if (!is_string($key) || $key === '' || !is_array($alternatives)) {
                continue;
            }
            $list = $this->stringList($alternatives);
            if ($list !== []) {
                $out[$key] = $list;
            }
        }
        return $out;
    }

    private function nullableString(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        return $raw;
    }
}
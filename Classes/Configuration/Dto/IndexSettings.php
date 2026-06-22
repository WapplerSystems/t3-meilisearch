<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Configuration\Dto;

/**
 * Index-level Meilisearch settings — the bag that gets pushed via
 * `$index->updateSettings(...)` by ApplyMeilisearchSettingsCommand.
 *
 * Intentionally narrow: only the index settings that SEAL does NOT
 * already manage. SEAL/Adapter takes care of searchableAttributes,
 * filterableAttributes and sortableAttributes derived from
 * SchemaProvider::getAdditionalFields() field flags; pushing them again
 * here would risk overwriting what SEAL provisioned.
 */
final readonly class IndexSettings
{
    /**
     * @param list<string>                 $rankingRules
     * @param list<string>                 $stopWords
     * @param array<string, list<string>>  $synonyms
     * @param list<string>                 $displayedAttributes
     * @param list<string>                 $typoDisableOnAttributes
     * @param list<string>                 $typoDisableOnWords
     */
    public function __construct(
        public array  $rankingRules,
        public bool   $typoToleranceEnabled,
        public int    $typoMinWordSizeForOneTypo,
        public int    $typoMinWordSizeForTwoTypos,
        public array  $typoDisableOnAttributes,
        public array  $typoDisableOnWords,
        public bool   $typoDisableOnNumbers,
        public array  $stopWords,
        public array  $synonyms,
        public ?string $distinctAttribute,
        public array  $displayedAttributes,
        public int    $paginationMaxTotalHits,
        public int    $facetingMaxValuesPerFacet,
        public string $facetingSortFacetValuesBy,
        public int    $searchCutoffMs,
    ) {}

    /**
     * Build the array payload accepted by Meilisearch's PHP SDK
     * (`$index->updateSettings(...)`). Empty values are dropped so users
     * can clear synonyms or stopwords explicitly by sending an empty
     * array, but unset / default values don't override engine defaults.
     *
     * @return array<string, mixed>
     */
    public function toMeilisearchPayload(): array
    {
        $payload = [
            'rankingRules' => $this->rankingRules,
            'typoTolerance' => [
                'enabled' => $this->typoToleranceEnabled,
                'minWordSizeForTypos' => [
                    'oneTypo'  => $this->typoMinWordSizeForOneTypo,
                    'twoTypos' => $this->typoMinWordSizeForTwoTypos,
                ],
                'disableOnAttributes' => $this->typoDisableOnAttributes,
                // disableOnWords prevents Meilisearch from treating these
                // tokens as fuzzy-match candidates. Indispensable for
                // brand / product tokens that visitors type intentionally
                // — without this, a near-spelling ("acmestore" vs the
                // actual brand "acme") would match via 1 typo (length ≥5)
                // and bury the genuine hit under unrelated near-matches.
                'disableOnWords' => $this->typoDisableOnWords,
                // disableOnNumbers (Meilisearch v1.12+): when true, never
                // fuzzy-match tokens that are pure digits. Pragmatic for
                // version numbers / SKUs ("Foo 24.1" must not match
                // "Foo 14.1" via a 1-digit typo).
                'disableOnNumbers' => $this->typoDisableOnNumbers,
            ],
            'stopWords' => $this->stopWords,
            // Meilisearch expects an OBJECT for `synonyms` ({}), not an array ([]).
            // PHP's json_encode serializes an empty associative array as [], which
            // the engine rejects with "Invalid value type at `.synonyms`". Force
            // object serialization via stdClass when there are no entries.
            'synonyms'  => $this->synonyms === [] ? new \stdClass() : $this->synonyms,
            'displayedAttributes' => $this->displayedAttributes,
            'pagination' => [
                'maxTotalHits' => $this->paginationMaxTotalHits,
            ],
            'faceting' => [
                'maxValuesPerFacet' => $this->facetingMaxValuesPerFacet,
                'sortFacetValuesBy' => ['*' => $this->facetingSortFacetValuesBy],
            ],
        ];

        if ($this->distinctAttribute !== null && $this->distinctAttribute !== '') {
            $payload['distinctAttribute'] = $this->distinctAttribute;
        }
        // 0 means "engine default / unlimited" — only push when actually set,
        // otherwise the engine's own default wins.
        if ($this->searchCutoffMs > 0) {
            $payload['searchCutoffMs'] = $this->searchCutoffMs;
        }

        return $payload;
    }
}
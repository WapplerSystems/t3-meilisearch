<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Configuration\Dto;

/**
 * One entry from settings.yaml › meilisearch.facets[*]. Drives how the
 * facet panel is rendered AND which attributes the search request asks
 * Meilisearch to compute facet counts for. The `attribute` MUST be
 * filterable on the SEAL schema, otherwise Meilisearch returns nothing.
 */
final readonly class FacetConfig
{
    public function __construct(
        public string $attribute,
        public string $label,
        public string $widget,
        public string $sort,
        public int    $maxItems,
        public bool   $collapsed,
        public bool   $showCounts,
        /** @var array<string, mixed> Extra widget-specific options (e.g. range min/max/step). */
        public array  $extra = [],
    ) {}

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $known = ['attribute', 'label', 'widget', 'sort', 'maxItems', 'collapsed', 'showCounts'];
        return new self(
            attribute:  (string)($raw['attribute'] ?? ''),
            label:      (string)($raw['label'] ?? ''),
            widget:     (string)($raw['widget'] ?? 'checkbox'),
            sort:       (string)($raw['sort'] ?? 'count'),
            maxItems:   (int)($raw['maxItems'] ?? 10),
            collapsed:  (bool)($raw['collapsed'] ?? false),
            showCounts: (bool)($raw['showCounts'] ?? true),
            extra:      array_diff_key($raw, array_flip($known)),
        );
    }
}
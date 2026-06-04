<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Configuration\Dto;

/**
 * One entry from settings.yaml › meilisearch.display[<type>]. Tells the
 * result-list renderer which partial to use for a given doctype, how to
 * project hit fields into the template's expected names, and which
 * Meilisearch highlight / crop attributes to request.
 *
 * `crop` entries are stored in Meilisearch's `<field>:<length>` syntax
 * — same shape passed to `attributesToCrop`, so they can be forwarded
 * verbatim to the SDK. Entries that omit `:N` get the site-wide
 * cropLength appended at request time.
 */
final readonly class DisplayConfig
{
    public function __construct(
        public string $type,
        public string $partial,
        /** @var array<string, string> Map of template-side name → hit field path (dot-notation). */
        public array  $fields,
        /** @var list<string> */
        public array  $highlight,
        /** @var list<string> */
        public array  $crop,
    ) {}

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(string $type, array $raw): self
    {
        return new self(
            type:      $type,
            partial:   (string)($raw['partial'] ?? 'Search/Result'),
            fields:    array_map('strval', (array)($raw['fields'] ?? [])),
            highlight: array_values(array_map('strval', (array)($raw['highlight'] ?? []))),
            crop:      array_values(array_map('strval', (array)($raw['crop'] ?? []))),
        );
    }
}
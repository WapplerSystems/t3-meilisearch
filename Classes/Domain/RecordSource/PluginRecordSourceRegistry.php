<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\RecordSource;

/**
 * Collects every tagged {@see PluginRecordSourceInterface} and exposes it by
 * its document type.
 *
 * Registration is pure DI: an implementation is auto-tagged
 * 'ws_meilisearch.plugin_record_source' via _instanceof in Services.yaml
 * (same mechanism as the schema providers), so dropping a class that
 * implements the interface — in this extension or in any other (e.g. a
 * product extension registering its own source) — is enough.
 *
 * The key is the source's getType(), which is the SAME value written into
 * each document's `type` field. That single string therefore ties together:
 * the scope source (here), the SchemaProvider, the per-type display partial
 * (meilisearch.display.<type>) and the `type` facet. So "registering a
 * source" inherently declares the record type used for faceting; the
 * human-readable facet label stays in meilisearch.facets + LLL.
 */
final class PluginRecordSourceRegistry
{
    /** @var array<string, PluginRecordSourceInterface> */
    private array $byType = [];

    /**
     * @param iterable<PluginRecordSourceInterface> $sources
     */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->byType[$source->getType()] = $source;
        }
    }

    public function get(string $type): ?PluginRecordSourceInterface
    {
        return $this->byType[$type] ?? null;
    }

    /**
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        return array_keys($this->byType);
    }
}
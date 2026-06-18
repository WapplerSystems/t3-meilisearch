<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import;

/**
 * Dispatches to the right {@see KnowledgeResourceSourceImporter} by name. Built
 * once at container construction with the full tagged-iterator of
 * importers; lookups are O(1) after the first call.
 *
 * Third-party extensions add their own importers by implementing the
 * interface — DI auto-tags them via the `_instanceof` rule in
 * Services.yaml, no extra registration code needed.
 */
final class SourceImporterRegistry
{
    /** @var array<string, KnowledgeResourceSourceImporter>|null memoised name → importer map */
    private ?array $byName = null;

    /** @var list<KnowledgeResourceSourceImporter> */
    private array $importers;

    /**
     * @param iterable<KnowledgeResourceSourceImporter> $importers DI tagged_iterator
     */
    public function __construct(iterable $importers)
    {
        $this->importers = is_array($importers) ? array_values($importers) : iterator_to_array($importers, false);
    }

    /**
     * @return list<KnowledgeResourceSourceImporter>
     */
    public function all(): array
    {
        return $this->importers;
    }

    public function get(string $name): KnowledgeResourceSourceImporter
    {
        $map = $this->byName();
        if (!isset($map[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'No KnowledgeResourceSourceImporter registered for name "%s". Known: %s',
                $name,
                implode(', ', array_keys($map)),
            ));
        }
        return $map[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->byName()[$name]);
    }

    /**
     * @return array<string, KnowledgeResourceSourceImporter>
     */
    private function byName(): array
    {
        if ($this->byName === null) {
            $this->byName = [];
            foreach ($this->importers as $importer) {
                $this->byName[$importer->name()] = $importer;
            }
        }
        return $this->byName;
    }
}
<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service\Import;

/**
 * Dispatches to the right {@see HelpDocSourceImporter} by name. Built
 * once at container construction with the full tagged-iterator of
 * importers; lookups are O(1) after the first call.
 *
 * Third-party extensions add their own importers by implementing the
 * interface — DI auto-tags them via the `_instanceof` rule in
 * Services.yaml, no extra registration code needed.
 */
final class SourceImporterRegistry
{
    /** @var array<string, HelpDocSourceImporter>|null memoised name → importer map */
    private ?array $byName = null;

    /** @var list<HelpDocSourceImporter> */
    private array $importers;

    /**
     * @param iterable<HelpDocSourceImporter> $importers DI tagged_iterator
     */
    public function __construct(iterable $importers)
    {
        $this->importers = is_array($importers) ? array_values($importers) : iterator_to_array($importers, false);
    }

    /**
     * @return list<HelpDocSourceImporter>
     */
    public function all(): array
    {
        return $this->importers;
    }

    public function get(string $name): HelpDocSourceImporter
    {
        $map = $this->byName();
        if (!isset($map[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'No HelpDocSourceImporter registered for name "%s". Known: %s',
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
     * @return array<string, HelpDocSourceImporter>
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
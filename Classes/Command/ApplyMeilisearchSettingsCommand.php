<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Meilisearch\Exceptions\ApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Configuration\SearchConfigurationProvider;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Pushes the index-level Meilisearch settings (rankingRules, typoTolerance,
 * stopWords, synonyms, distinctAttribute, displayedAttributes, pagination,
 * faceting, searchCutoffMs) from Site Settings to each site's Meilisearch
 * index.
 *
 * Complements ws_meilisearch:setup-index-config (which manages the
 * EXT:index Configuration record) and ws_meilisearch:reindex (which
 * writes documents). Run this command after changing relevance config
 * in settings.yaml — the engine picks up the new settings without a
 * full reindex.
 *
 * SEAL handles searchableAttributes / filterableAttributes /
 * sortableAttributes from SchemaProvider field flags; this command
 * deliberately does not touch them to avoid fighting the adapter.
 */
#[AsCommand(
    name: 'ws_meilisearch:apply-settings',
    description: 'Push Meilisearch index settings (ranking, typo tolerance, stop words, synonyms, …) from Site Settings to the engine.',
)]
final class ApplyMeilisearchSettingsCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SearchEngineFactory $engineFactory,
        private readonly SearchConfigurationProvider $configProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Limit to a single site identifier. Default: all sites with meilisearch.url configured.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the payload, do not push.')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for Meilisearch tasks to complete. Overrides meilisearch.sync.waitForTasks.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('site');
        $dry  = (bool)$input->getOption('dry-run');
        $noWaitFlag = (bool)$input->getOption('no-wait');

        $hadError = false;
        $touched = 0;

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($only !== null && $site->getIdentifier() !== (string)$only) {
                continue;
            }

            $client = $this->engineFactory->createClientForSite($site);
            if ($client === null) {
                $io->writeln(sprintf(
                    '<comment>skip</comment> %s: no meilisearch.url configured',
                    $site->getIdentifier(),
                ));
                continue;
            }
            $indexUid = $this->engineFactory->getIndexName($site);
            $payload  = $this->configProvider->indexSettings($site)->toMeilisearchPayload();
            $payload  = $this->mergeSchemaAttributes($payload, $site, $indexUid);
            $wait     = !$noWaitFlag && (bool)$site->getSettings()->get('meilisearch.sync.waitForTasks', true);

            $io->section(sprintf('%s → %s', $site->getIdentifier(), $indexUid));

            if ($dry) {
                $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                $touched++;
                continue;
            }

            try {
                // Ensure the index exists — settings can only be pushed to an
                // existing index. SEAL normally creates it on first indexing
                // run; this lets operators apply settings before any docs
                // have been written.
                try {
                    $client->getIndex($indexUid);
                } catch (ApiException $missing) {
                    if ($missing->httpStatus !== 404) {
                        throw $missing;
                    }
                    $createTask = $client->createIndex($indexUid, ['primaryKey' => 'id']);
                    $taskUid = (int)($createTask['taskUid'] ?? 0);
                    if ($wait && $taskUid > 0) {
                        $client->waitForTask($taskUid);
                    }
                    $io->writeln(sprintf('  created index (task #%d)', $taskUid));
                }

                $task = $client->index($indexUid)->updateSettings($payload);
                $taskUid = (int)($task['taskUid'] ?? 0);
                if ($wait && $taskUid > 0) {
                    $client->index($indexUid)->waitForTask($taskUid);
                    $io->writeln(sprintf('  applied (task #%d) — done', $taskUid));
                } else {
                    $io->writeln(sprintf('  applied (task #%d) — queued', $taskUid));
                }
                $touched++;
            } catch (\Throwable $e) {
                $io->error(sprintf('  failed: %s', $e->getMessage()));
                $hadError = true;
            }
        }

        if ($touched === 0 && !$hadError) {
            $io->warning('No sites processed. Check --site identifier or meilisearch.url settings.');
            return Command::SUCCESS;
        }
        return $hadError ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * SEAL pushes searchable/filterable/sortable attributes only on initial
     * createIndex(). For existing indexes a new field added to baseFields()
     * (e.g. `boost`) never reaches Meilisearch unless we re-push them — so
     * derive them from the SEAL schema here and merge into the payload.
     *
     * Distinct + facet fields fold into filterable, mirroring how
     * MeilisearchSchemaManager::createIndex() composes the list.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mergeSchemaAttributes(array $payload, Site $site, string $indexUid): array
    {
        $schema = $this->engineFactory->getSchemaForSite($site);
        $index = $schema->indexes[$indexUid] ?? null;
        if ($index === null) {
            return $payload;
        }

        $filterable = array_values(array_unique(array_merge(
            $index->filterableFields,
            $index->distinctFields,
            $index->facetFields,
        )));

        $payload['searchableAttributes'] = $index->searchableFields;
        $payload['filterableAttributes'] = $filterable;
        $payload['sortableAttributes']   = $index->sortableFields;

        return $payload;
    }
}
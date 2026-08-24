<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Meilisearch\Contracts\DocumentsQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Domain\Schema\SchemaProviderInterface;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Compare what the schema providers produce against what is actually in
 * the index, by document id.
 *
 * Reindex statistics answer "how much work did this run do", not "did
 * the work land". Those differ more often than one would like: a
 * document can be written on every single run and still never appear —
 * two providers claiming the same id, an eviction rule deleting it right
 * back, a filter the index applies but the provider does not. None of
 * that produces an error anywhere; the only symptom is a reindex that
 * keeps paying for embeddings it seems to have paid for already.
 *
 * This command names the documents involved:
 *
 *   PHANTOM  produced by a provider, absent from the index
 *   STALE    in the index, no longer produced by any provider
 *   DUPLICATE  produced more than once in a single run, same id
 *
 * It only reads. No document is written, no embedding is fetched.
 */
#[AsCommand(
    name: 'ws_meilisearch:index-audit',
    description: 'Compare provider output against the index by document id — finds phantom, stale and duplicate documents.'
)]
final class IndexAuditCommand extends Command
{
    /**
     * @param iterable<SchemaProviderInterface> $schemaProviders
     */
    public function __construct(
        private readonly iterable $schemaProviders,
        private readonly SearchEngineFactory $engineFactory,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: first Meilisearch-configured site)')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'How many example ids to print per category', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $show = max(0, (int)$input->getOption('show'));

        $site = null;
        $siteId = $input->getArgument('site');
        foreach ($this->siteFinder->getAllSites() as $candidate) {
            if ($siteId !== null) {
                if ($candidate->getIdentifier() === $siteId) {
                    $site = $candidate;
                    break;
                }
                continue;
            }
            if ((string)$candidate->getSettings()->get('meilisearch.url', '') !== '') {
                $site = $candidate;
                break;
            }
        }
        if ($site === null) {
            $io->error('No matching site found.');
            return Command::FAILURE;
        }

        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            $io->error('Site "' . $site->getIdentifier() . '" has no meilisearch.url configured.');
            return Command::FAILURE;
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $io->section('Site ' . $site->getIdentifier() . ' · index ' . $indexName);

        // 1. What do the providers produce? Documents are built in full
        //    (that is the point — the id is only known afterwards) but
        //    discarded immediately; only ids and types are kept.
        $produced = [];       // id => type
        $duplicates = [];     // id => list of producing providers
        $perProvider = [];    // provider => count
        $totalYielded = 0;
        foreach ($this->schemaProviders as $provider) {
            // Fully qualified, not the short name: two packages can ship
            // a provider class with the SAME short name (a project
            // package and the extension both had a WhatsnewSchemaProvider
            // registered, which made the duplicate report read as one
            // provider yielding twice instead of two providers colliding).
            $name = $provider::class;
            $count = 0;
            foreach ($provider->iterateDocuments($site) as $document) {
                $id = (string)($document['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                if (isset($produced[$id])) {
                    // Record who produced it, both times — a class name
                    // appearing twice means the same provider yields the
                    // id twice, two different names mean two providers
                    // are fighting over it. That distinction is the
                    // whole point of the report.
                    $duplicates[$id][] = $name;
                } else {
                    $duplicates[$id] = [$name];
                }
                $produced[$id] = (string)($document['type'] ?? '?');
                $count++;
                $totalYielded++;
            }
            // += rather than =: a provider class registered twice in the
            // container is iterated twice, and overwriting would hide
            // exactly the situation being investigated.
            $perProvider[$name] = ($perProvider[$name] ?? 0) + $count;
        }
        // Only ids seen more than once are interesting.
        $duplicates = array_filter($duplicates, static fn(array $p): bool => count($p) > 1);

        // 2. What is in the index?
        $indexed = [];
        $offset = 0;
        do {
            $result = $client->index($indexName)->getDocuments(
                (new DocumentsQuery())->setFields(['id', 'type'])->setOffset($offset)->setLimit(1000),
            );
            $rows = $result->getResults();
            foreach ($rows as $row) {
                $indexed[(string)($row['id'] ?? '')] = (string)($row['type'] ?? '?');
            }
            $offset += count($rows);
            $total = $result->getTotal();
        } while ($rows !== [] && $offset < $total);

        // 3. Compare.
        $phantom = array_diff_key($produced, $indexed);
        $stale = array_diff_key($indexed, $produced);

        $io->writeln(sprintf('  produced by providers : %d yielded, %d distinct ids', $totalYielded, count($produced)));
        foreach ($perProvider as $name => $count) {
            $io->writeln(sprintf('      %-62s %d', $name, $count));
        }
        $io->writeln(sprintf('  in index              : %d', count($indexed)));
        $io->newLine();

        $this->report($io, 'PHANTOM — produced but not in the index', $phantom, $show);
        $this->report($io, 'STALE — in the index but no longer produced', $stale, $show, true);
        if ($duplicates !== []) {
            $io->writeln(sprintf('<comment>DUPLICATE — same id produced more than once: %d</comment>', count($duplicates)));
            $byProducers = [];
            foreach ($duplicates as $producers) {
                $byProducers[implode(' + ', $producers)] = ($byProducers[implode(' + ', $producers)] ?? 0) + 1;
            }
            arsort($byProducers);
            foreach ($byProducers as $combination => $count) {
                $io->writeln(sprintf('      %s%s%d', $combination, PHP_EOL . '        → ', $count));
            }
            foreach (array_slice($duplicates, 0, $show, true) as $id => $producers) {
                $io->writeln(sprintf('      %s (%d× — %s)', $id, count($producers), implode(', ', $producers)));
            }
            $io->newLine();
        }

        if ($phantom === [] && $stale === [] && $duplicates === []) {
            $io->success('Providers and index agree on every document id.');
            return Command::SUCCESS;
        }
        return Command::SUCCESS;
    }

    /**
     * @param array<string,string> $ids id => type
     */
    private function report(SymfonyStyle $io, string $title, array $ids, int $show, bool $staleNote = false): void
    {
        if ($ids === []) {
            $io->writeln('<info>' . $title . ': none</info>');
            return;
        }
        $byType = [];
        foreach ($ids as $type) {
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }
        arsort($byType);
        $io->writeln(sprintf('<comment>%s: %d</comment>', $title, count($ids)));
        foreach ($byType as $type => $count) {
            $io->writeln(sprintf('      %-22s %d', $type, $count));
        }
        foreach (array_slice(array_keys($ids), 0, $show) as $id) {
            $io->writeln('      ' . $id);
        }
        if ($staleNote) {
            $io->writeln('      (a reindex never removes these — only an eviction rule or --rebuild does)');
            $io->writeln('      NOTE: `page` documents come from the EXT:index crawl bridge, not from a');
            $io->writeln('      schema provider, so they always appear here. Not a defect.');
        }
        $io->newLine();
    }
}

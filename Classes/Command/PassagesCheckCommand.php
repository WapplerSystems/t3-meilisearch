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
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Domain\Schema\FilePassageSchemaProvider;
use WapplerSystems\Meilisearch\Domain\Schema\FileSchemaProvider;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;
use WapplerSystems\Meilisearch\Service\Tika\TextExtractor;

/**
 * Is every document the site indexes actually available to the assistant,
 * page by page and with a vector?
 *
 * Compares the files FileSchemaProvider admits (restricted to
 * meilisearch.passages.extensions) with the file_passage documents in the
 * index, and names a reason for every gap: Tika failed, the file has no text
 * layer (a scan — needs meilisearch.tika.ocrEnabled), or it was simply not
 * indexed. A passage without a vector is invisible to the semantic half of
 * the search, so those are counted too.
 *
 * Reads only. Files without passages are re-extracted to name the reason;
 * --deep re-extracts every file to compare its page count with its passages.
 * Both are served from the Tika cache unless the file changed — after a
 * cache:flush, --deep runs Tika (and OCR) over the whole corpus again.
 *
 * Exit code 1 when a file with text has no or too few passages, or a passage
 * lacks its vector — usable as a check after a deploy. Files without any
 * text are reported but do not fail the run: there is nothing to index.
 */
#[AsCommand(
    name: 'ws_meilisearch:passages-check',
    description: 'Check that every indexable document has its page passages, with vectors, in the index.'
)]
final class PassagesCheckCommand extends Command
{
    /**
     * Pages shorter than this are not indexed (meilisearch.passages.minChars
     * default) and do not count as missing.
     */
    private const MIN_CHARS_DEFAULT = 80;

    public function __construct(
        private readonly FileSchemaProvider $files,
        private readonly TextExtractor $textExtractor,
        private readonly SearchEngineFactory $engineFactory,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: first Meilisearch-configured site)')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'How many files to list per category', '50')
            ->addOption('deep', null, InputOption::VALUE_NONE, 'Also compare every indexed file\'s pages with its passages (re-extracts all files; slow on a cold Tika cache)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $show = max(0, (int)$input->getOption('show'));
        $deep = (bool)$input->getOption('deep');

        $site = $this->resolveSite($input->getArgument('site'));
        if ($site === null) {
            $io->error('No matching site found.');
            return Command::FAILURE;
        }
        $settings = $site->getSettings();
        if (!(bool)$settings->get('meilisearch.passages.enabled', false)) {
            $io->warning('meilisearch.passages.enabled is off for site "' . $site->getIdentifier() . '" — nothing to check.');
            return Command::SUCCESS;
        }
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            $io->error('Site "' . $site->getIdentifier() . '" has no meilisearch.url configured.');
            return Command::FAILURE;
        }
        $indexName = $this->engineFactory->getIndexName($site);
        $io->section('Site ' . $site->getIdentifier() . ' · index ' . $indexName);

        $extensions = $this->extensions($site);
        $minChars = max(0, (int)$settings->get('meilisearch.passages.minChars', self::MIN_CHARS_DEFAULT));

        // 1. What the indexer admits.
        $eligible = [];
        foreach ($this->files->eligibleFiles($site) as [$file]) {
            if (in_array(strtolower((string)$file->getExtension()), $extensions, true)) {
                $eligible[(int)$file->getUid()] = $file;
            }
        }

        // 2. What the index holds. Vectors are fetched to tell whether they
        //    exist; at a few thousand passages that is a few hundred MB of
        //    JSON over the run, hence the modest page size.
        $passages = [];
        $languages = [];
        $restricted = [];
        $withoutVector = [];
        $offset = 0;
        do {
            $result = $client->index($indexName)->getDocuments(
                (new DocumentsQuery())
                    ->setFilter(['type = "' . FilePassageSchemaProvider::TYPE . '"'])
                    ->setFields(['id', 'uid', 'contentLanguage', 'accessGroups'])
                    ->setRetrieveVectors(true)
                    ->setOffset($offset)
                    ->setLimit(200),
            );
            $rows = $result->getResults();
            foreach ($rows as $row) {
                $uid = (int)($row['uid'] ?? 0);
                $passages[$uid] = ($passages[$uid] ?? 0) + 1;
                $languages[$uid] = (string)($row['contentLanguage'] ?? '');
                $restricted[$uid] = ($row['accessGroups'] ?? []) !== [];
                $vector = $row['_vectors']['default']['embeddings'] ?? ($row['_vectors']['default'] ?? null);
                if (!is_array($vector) || $vector === []) {
                    $withoutVector[] = (string)($row['id'] ?? '');
                }
            }
            $offset += count($rows);
        } while ($rows !== [] && $offset < $result->getTotal());

        // 3. Compare file by file.
        $missing = [];
        $textless = [];
        $incomplete = [];
        foreach ($eligible as $uid => $file) {
            $have = $passages[$uid] ?? 0;
            if ($have > 0 && !$deep) {
                continue;
            }
            $label = sprintf('%6d  %s', $uid, $file->getIdentifier());
            try {
                $extraction = $this->textExtractor->extractPages($file, $site);
            } catch (\Throwable $e) {
                $extraction = ExtractionResult::failed($e->getMessage());
            }
            $pages = $extraction->status === ExtractionResult::SUCCESS ? $extraction->pages : [];
            $textPages = count(array_filter($pages, static fn (string $p): bool => mb_strlen(trim($p)) >= $minChars));
            if ($have > 0 && $have >= $textPages) {
                continue;
            }
            if ($have > 0) {
                $incomplete[] = sprintf('%s  → %d passages for %d pages with text', $label, $have, $textPages);
            } elseif ($extraction->status === ExtractionResult::FAILED) {
                $missing[] = $label . '  → Tika failed: ' . $extraction->reason;
            } elseif ($textPages === 0) {
                $textless[] = sprintf('%s  → no text (%s)', $label, $extraction->status === ExtractionResult::SUCCESS
                    ? count($pages) . ' pages, none with text'
                    : $extraction->reason);
            } else {
                $missing[] = sprintf('%s  → not indexed although %d pages have text', $label, $textPages);
            }
        }
        $orphans = array_diff_key($passages, $eligible);

        $indexedFiles = array_intersect_key($passages, $eligible);
        $io->writeln(sprintf('  admitted files (%s) : %d', implode(', ', $extensions), count($eligible)));
        $io->writeln(sprintf('  with passages        : %d files, %d passages', count($indexedFiles), array_sum($indexedFiles)));
        $byLanguage = [];
        foreach (array_keys($indexedFiles) as $uid) {
            $code = $languages[$uid] !== '' ? $languages[$uid] : '?';
            $byLanguage[$code] = ($byLanguage[$code] ?? 0) + 1;
        }
        arsort($byLanguage);
        $io->writeln('  text language        : ' . implode(', ', array_map(
            static fn (string $code, int $count): string => $code . ' ' . $count,
            array_keys($byLanguage),
            $byLanguage,
        )));
        $io->writeln(sprintf('  login only           : %d files', count(array_filter(array_intersect_key($restricted, $eligible)))));
        $io->newLine();

        $this->listing($io, 'Passages without a vector (not found by semantic search)', $withoutVector, $show);
        $this->listing($io, 'Files missing from the index', $missing, $show);
        $this->listing($io, 'Files with too few passages', $incomplete, $show);
        $this->listing($io, (bool)$settings->get('meilisearch.tika.ocrEnabled', false)
            ? 'Files without any text, even with OCR (drawings, placeholders)'
            : 'Files without any text — scans need meilisearch.tika.ocrEnabled', $textless, $show);
        if ($orphans !== []) {
            $io->writeln(sprintf('<comment>Passages of %d files the site no longer admits — a full reindex removes them.</comment>', count($orphans)));
        }

        if ($withoutVector !== [] || $missing !== [] || $incomplete !== []) {
            $io->error('Not every document is available to the assistant.');
            return Command::FAILURE;
        }
        $io->success('Every file with text is indexed page by page, every passage has its vector.');
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $lines
     */
    private function listing(SymfonyStyle $io, string $title, array $lines, int $show): void
    {
        if ($lines === []) {
            return;
        }
        $io->writeln(sprintf('<comment>%s: %d</comment>', $title, count($lines)));
        foreach (array_slice($lines, 0, $show) as $line) {
            $io->writeln('  ' . $line);
        }
        if (count($lines) > $show) {
            $io->writeln(sprintf('  … and %d more (--show)', count($lines) - $show));
        }
        $io->newLine();
    }

    /**
     * @return list<string>
     */
    private function extensions(Site $site): array
    {
        $raw = $site->getSettings()->get('meilisearch.passages.extensions', ['pdf']);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        $out = [];
        foreach ((array)$raw as $ext) {
            $ext = ltrim(strtolower(trim((string)$ext)), '.');
            if ($ext !== '') {
                $out[] = $ext;
            }
        }
        return $out === [] ? ['pdf'] : array_values(array_unique($out));
    }

    private function resolveSite(?string $siteId): ?Site
    {
        foreach ($this->siteFinder->getAllSites() as $candidate) {
            if ($siteId !== null) {
                if ($candidate->getIdentifier() === $siteId) {
                    return $candidate;
                }
                continue;
            }
            if ((string)$candidate->getSettings()->get('meilisearch.url', '') !== '') {
                return $candidate;
            }
        }
        return null;
    }
}

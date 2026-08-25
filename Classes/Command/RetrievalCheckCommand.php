<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Rag\RagService;

/**
 * Check that the documents an answer needs actually reach the context.
 *
 * RAG quality was measured only through the finished answer: one LLM call
 * per test, scored by cosine similarity against an expected wording. That
 * metric costs money, takes seconds per case, and still moves by ~0.1
 * between identical runs — so a retrieval problem hides inside the noise
 * instead of standing out.
 *
 * Whether the right source made it into the top-K is a different kind of
 * question: deterministic, free, and answerable in milliseconds. It is
 * also the first thing worth knowing when an answer is wrong, because no
 * amount of prompt work rescues a context that never contained the
 * answer.
 *
 * Reads `expected_doc_ids` from the existing RAG test records, so both
 * layers describe the same questions instead of drifting apart.
 */
#[AsCommand(
    name: 'ws_meilisearch:retrieval-check',
    description: 'Verify that expected documents reach the RAG context for each test question. No LLM calls.'
)]
final class RetrievalCheckCommand extends Command
{
    public function __construct(
        private readonly RagService $ragService,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: the test record\'s own site, else the first configured one)')
            ->addOption('question', null, InputOption::VALUE_REQUIRED, 'Check one ad-hoc question instead of the stored tests — prints the retrieved context.')
            ->addOption('show', null, InputOption::VALUE_REQUIRED, 'How many retrieved documents to list per question', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $show = max(1, (int)$input->getOption('show'));
        $siteArg = $input->getArgument('site');

        $adHoc = (string)($input->getOption('question') ?? '');
        if ($adHoc !== '') {
            $site = $this->resolveSite($siteArg);
            if ($site === null) {
                $io->error('No Meilisearch-configured site found.');
                return Command::FAILURE;
            }
            $io->section($adHoc);
            $this->printHits($io, $this->ragService->retrieveOnly($site, $adHoc), $show, []);
            return Command::SUCCESS;
        }

        $tests = $this->fetchTests();
        if ($tests === []) {
            $io->warning('No RAG test records with expected_doc_ids set — nothing to check.');
            return Command::SUCCESS;
        }

        $rows = [];
        $misses = 0;
        foreach ($tests as $test) {
            $site = $this->resolveSite($siteArg ?? ($test['site_identifier'] !== '' ? $test['site_identifier'] : null));
            if ($site === null) {
                $io->error('No site for test #' . $test['uid']);
                return Command::FAILURE;
            }
            $expected = $this->splitIds($test['expected_doc_ids']);
            $hits = $this->ragService->retrieveOnly($site, $test['question']);
            $ids = array_map(static fn (array $h): string => (string)($h['id'] ?? ''), $hits);

            $found = [];
            $missing = [];
            foreach ($expected as $id) {
                $rank = array_search($id, $ids, true);
                if ($rank === false) {
                    $missing[] = $id;
                } else {
                    $found[] = $id . ' (#' . ($rank + 1) . ')';
                }
            }
            if ($missing !== []) {
                $misses++;
            }
            $rows[] = [
                $test['uid'],
                mb_substr($test['title'], 0, 34),
                $missing === [] ? '<fg=green>HIT</>' : '<fg=red>MISS</>',
                implode(', ', $found) ?: '—',
                implode(', ', $missing) ?: '—',
            ];
            if ($missing !== [] && $output->isVerbose()) {
                $io->writeln('  <comment>#' . $test['uid'] . '</comment> retrieved instead:');
                $this->printHits($io, $hits, $show, $expected);
            }
        }

        $io->table(['uid', 'title', 'result', 'found (rank)', 'missing'], $rows);
        $io->writeln(sprintf(
            '<info>Summary:</info> %d of %d questions retrieved every expected document.',
            count($rows) - $misses,
            count($rows),
        ));
        return $misses > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<array<string,mixed>> $hits
     * @param list<string> $expected
     */
    private function printHits(SymfonyStyle $io, array $hits, int $show, array $expected): void
    {
        if ($hits === []) {
            $io->writeln('    <comment>(nothing retrieved)</comment>');
            return;
        }
        foreach (array_slice($hits, 0, $show) as $i => $hit) {
            $id = (string)($hit['id'] ?? '');
            $mark = in_array($id, $expected, true) ? '<fg=green>*</>' : ' ';
            $io->writeln(sprintf('    %s #%d %-14s %s', $mark, $i + 1, $id, mb_substr((string)($hit['title'] ?? ''), 0, 60)));
        }
    }

    /**
     * @return list<string>
     */
    private function splitIds(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @return list<array{uid:int,title:string,question:string,expected_doc_ids:string,site_identifier:string}>
     */
    private function fetchTests(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_wsmeilisearch_ragtest');
        $rows = $qb->select('uid', 'title', 'question', 'expected_doc_ids', 'site_identifier')
            ->from('tx_wsmeilisearch_ragtest')
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->neq('expected_doc_ids', $qb->createNamedParameter('')),
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $r): array => [
            'uid' => (int)$r['uid'],
            'title' => (string)$r['title'],
            'question' => (string)$r['question'],
            'expected_doc_ids' => (string)$r['expected_doc_ids'],
            'site_identifier' => (string)$r['site_identifier'],
        ], $rows);
    }

    private function resolveSite(?string $identifier): ?Site
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($identifier !== null && $identifier !== '') {
                if ($site->getIdentifier() === $identifier) {
                    return $site;
                }
                continue;
            }
            if ((string)$site->getSettings()->get('meilisearch.url', '') !== '') {
                return $site;
            }
        }
        return null;
    }
}

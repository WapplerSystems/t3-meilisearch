<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\IndexerService;

#[AsCommand(
    name: 'ws_meilisearch:reindex',
    description: 'Rebuild Meilisearch indexes for one or all sites.'
)]
final class ReindexCommand extends Command
{
    public function __construct(
        private readonly IndexerService $indexer,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (omit to index all sites)')
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Drop and recreate the index before populating it (lossy — index is unavailable while rebuilding).')
            ->addOption('skip-embedder', null, InputOption::VALUE_NONE, 'Do not push the embedder configuration. Use when troubleshooting a wedged hybrid setup — keyword search keeps working.')
            ->addOption('force-embed', null, InputOption::VALUE_NONE, 'Re-embed every document instead of re-using the vectors already in the index. Only needed after an embedder model change or when vectors are suspected to be stale — on a large corpus this is what exhausts the provider\'s token quota.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteId = $input->getArgument('site');
        $rebuild = (bool)$input->getOption('rebuild');
        $skipEmbedder = (bool)$input->getOption('skip-embedder');
        $forceEmbed = (bool)$input->getOption('force-embed');
        $failed = 0;

        $sites = $siteId !== null
            ? [$this->siteFinder->getSiteByIdentifier($siteId)]
            : $this->siteFinder->getAllSites();

        foreach ($sites as $site) {
            $io->section('Site: ' . $site->getIdentifier());
            if (!$this->indexer->ensureSchema($site, $rebuild, $skipEmbedder)) {
                $io->warning('  → not configured for Meilisearch (skip)');
                continue;
            }
            // Progress every 1000 documents: a full run takes hours and
            // "silent for two hours" is indistinguishable from "wedged in
            // a 429 retry loop", which is exactly how the last stalled
            // production reindex went unnoticed.
            $progress = static function ($stats) use ($io): void {
                if ($stats->seen % 1000 < 100) {
                    $io->writeln('     ' . $stats->summary());
                }
            };
            $count = $this->indexer->indexAll($site, $forceEmbed, $progress);
            $stats = $this->indexer->getLastStats();
            if ($stats === null) {
                $io->writeln(sprintf('  → %d documents indexed', $count));
                continue;
            }
            $io->writeln('  → ' . $stats->summary());
            $failed += $stats->failed;
            if ($stats->failed > 0) {
                // Loud on purpose: these documents are NOT in the index.
                // Meilisearch rejects vectorless documents against the
                // userProvided embedder, so pushing them anyway would have
                // dropped them silently — the failure mode that kept
                // production pages missing for months.
                $io->warning(sprintf(
                    '%d documents could not be embedded and were therefore not indexed. '
                    . 'Check the TYPO3 log for the ids, then re-run once the provider quota has recovered.',
                    $stats->failed,
                ));
            }
        }
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
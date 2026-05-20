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
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Drop and recreate the index before populating it (lossy — index is unavailable while rebuilding).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteId = $input->getArgument('site');
        $rebuild = (bool)$input->getOption('rebuild');

        $sites = $siteId !== null
            ? [$this->siteFinder->getSiteByIdentifier($siteId)]
            : $this->siteFinder->getAllSites();

        foreach ($sites as $site) {
            $io->section('Site: ' . $site->getIdentifier());
            if (!$this->indexer->ensureSchema($site, $rebuild)) {
                $io->warning('  → not configured for Meilisearch (skip)');
                continue;
            }
            $count = $this->indexer->indexAll($site);
            $io->writeln(sprintf('  → %d documents indexed', $count));
        }
        return Command::SUCCESS;
    }
}
<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;

/**
 * Inspect a single document in a site's Meilisearch index by id.
 * Useful when a frontend search hit looks wrong or a record edit didn't
 * propagate — checks the actual stored document rather than guessing.
 */
#[AsCommand(
    name: 'ws_meilisearch:document',
    description: 'Fetch a single document by id from a site\'s Meilisearch index.'
)]
final class DocumentCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SearchEngineFactory $engineFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Document id, e.g. pages-42, news-17, sys_file-99')
            ->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: first Meilisearch-configured site)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string)$input->getArgument('id');
        $siteId = $input->getArgument('site');

        $site = $this->resolveSite($siteId);
        if ($site === null) {
            $io->error('No Meilisearch-configured site found' . ($siteId !== null ? ' for "' . $siteId . '"' : ''));
            return Command::FAILURE;
        }

        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            $io->error('Site "' . $site->getIdentifier() . '" has no meilisearch.url configured');
            return Command::FAILURE;
        }
        $indexName = $this->engineFactory->getIndexName($site);

        try {
            $doc = $client->index($indexName)->getDocument($id);
        } catch (\Throwable $e) {
            $io->error(sprintf('Document "%s" not found in index "%s" (site %s): %s',
                $id, $indexName, $site->getIdentifier(), $e->getMessage()));
            return Command::FAILURE;
        }

        $io->writeln('<info>Site:</info> ' . $site->getIdentifier());
        $io->writeln('<info>Index:</info> ' . $indexName);
        $io->writeln('<info>Document:</info> ' . $id);
        $io->newLine();
        $io->writeln((string)json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }

    private function resolveSite(?string $siteId): ?\TYPO3\CMS\Core\Site\Entity\Site
    {
        if ($siteId !== null) {
            try {
                return $this->siteFinder->getSiteByIdentifier($siteId);
            } catch (\Throwable) {
                return null;
            }
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (trim((string)$site->getSettings()->get('meilisearch.url', '')) !== '') {
                return $site;
            }
        }
        return null;
    }
}

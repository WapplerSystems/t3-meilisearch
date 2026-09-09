<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Lochmueller\Index\Configuration\Configuration;
use Lochmueller\Index\Configuration\ConfigurationLoader;
use Lochmueller\Index\Indexing\ActiveIndexing;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;

/**
 * Re-index one page.
 *
 * Pages are the one document type this extension does not own: they arrive
 * through EXT:index, which walks the page tree for an IndexConfiguration
 * record and hands each page to our listener. Consequently
 * `ws_meilisearch:index-record pages <uid>` does NOT work — no schema provider
 * claims the `pages` table, and the command reports "NOT indexed" without any
 * statistics, which reads like a refusal but means "nobody handles this".
 *
 * What existed instead: `index:queue`, which enqueues an entire configuration
 * (on the LINEAR install: 14,000+ pages), and saving the page in the backend,
 * which needs a backend. Neither is what you want when one page's document has
 * gone stale — and one page going stale is a normal thing to happen, e.g. when
 * an earlier indexing attempt died on an embedding timeout.
 *
 * This command is the third way, and it is not a reimplementation: it calls
 * exactly what the backend save calls (DataHandlerUpdateHook →
 * ConfigurationLoader::loadByPageTraversing() →
 * Configuration::modifyForPartialIndexing() → ActiveIndexing::fillQueue()), so
 * a page indexed from here is indexed the way production indexes it.
 *
 * Asynchronous: fillQueue() dispatches Messenger messages, and the index
 * workers do the work. The command returns as soon as the messages are queued,
 * so "queued" is not "done" — check the document afterwards.
 */
#[AsCommand(
    name: 'ws_meilisearch:index-page',
    description: 'Queue a single page for re-indexing through EXT:index — the same path a backend save takes.',
)]
final class IndexPageCommand extends Command
{
    public function __construct(
        private readonly ConfigurationLoader $configurationLoader,
        private readonly ActiveIndexing $activeIndexing,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('page', InputArgument::REQUIRED, 'Page uid to re-index.')
            ->addOption(
                'with-files',
                null,
                InputOption::VALUE_NONE,
                'Also queue the files attached to the page. Off by default, matching what a backend '
                . 'save does: the page changed, its PDFs did not, and re-extracting them is the '
                . 'expensive half.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = (int)$input->getArgument('page');
        if ($pageId < 1) {
            $io->error('Page uid must be a positive integer.');
            return Command::INVALID;
        }

        // Traverses up the rootline to the nearest IndexConfiguration record,
        // exactly as the DataHandler hook does. A page no configuration covers
        // cannot be indexed, and saying so is more useful than queueing a
        // message that quietly indexes nothing.
        // A uid that is not a page at all reaches RootlineUtility and comes
        // back as a PageNotFoundException ("Broken rootline") — a stack trace
        // for a typo. Catch it and say which page was meant.
        try {
            $configuration = $this->configurationLoader->loadByPageTraversing($pageId);
        } catch (PageNotFoundException $e) {
            $io->error(sprintf('Page %d does not exist (%s).', $pageId, $e->getMessage()));
            return Command::FAILURE;
        }
        if (!$configuration instanceof Configuration) {
            $io->error(sprintf(
                'No EXT:index configuration covers page %d. Check the IndexConfiguration records '
                . 'and their crawl depth — traversal stops at a page that carries its own configuration.',
                $pageId,
            ));
            return Command::FAILURE;
        }

        $this->activeIndexing->fillQueue(
            $configuration->modifyForPartialIndexing($pageId),
            !$input->getOption('with-files'),
        );

        $io->success(sprintf(
            'Queued page %d for re-indexing (configuration #%d, %s).',
            $pageId,
            $configuration->configurationId,
            $configuration->technology->value,
        ));
        $io->writeln('The index workers pick this up asynchronously — verify the document afterwards.');

        return Command::SUCCESS;
    }
}

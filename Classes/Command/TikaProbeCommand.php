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
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use WapplerSystems\Meilisearch\Service\Tika\ExtractionResult;
use WapplerSystems\Meilisearch\Service\Tika\TextExtractor;

/**
 * Runs one FAL file through the same Tika pipeline the indexer uses,
 * with the same per-site mime/OCR/timeout settings. Validates the Tika
 * stack before kicking off a big reindex — surfaces "mime not allowed"
 * or "file too large" or "Tika returned 400" without scanning the queue.
 */
#[AsCommand(
    name: 'ws_meilisearch:tika-probe',
    description: 'Extract one FAL file via Apache Tika using a site\'s settings, show the result.'
)]
final class TikaProbeCommand extends Command
{
    private const PREVIEW_LENGTH = 600;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ResourceFactory $resourceFactory,
        private readonly TextExtractor $textExtractor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'FAL combined identifier (e.g. 1:/path/file.pdf) OR sys_file uid')
            ->addArgument('site', InputArgument::OPTIONAL, 'Site identifier (default: first Meilisearch-configured site)')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Show full extracted text instead of a preview.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileArg = (string)$input->getArgument('file');
        $siteId = $input->getArgument('site');
        $full = (bool)$input->getOption('full');

        $site = $this->resolveSite($siteId);
        if ($site === null) {
            $io->error('No Meilisearch-configured site found' . ($siteId !== null ? ' for "' . $siteId . '"' : ''));
            return Command::FAILURE;
        }

        $file = $this->resolveFile($fileArg);
        if ($file === null) {
            $io->error('Could not resolve "' . $fileArg . '" to a FAL file');
            return Command::FAILURE;
        }

        $io->writeln('<info>Site:</info>  ' . $site->getIdentifier());
        $io->writeln('<info>File:</info>  ' . $file->getCombinedIdentifier());
        $io->writeln('<info>Mime:</info>  ' . $file->getMimeType());
        $io->writeln('<info>Size:</info>  ' . number_format($file->getSize()) . ' bytes');
        $tikaUrl = trim((string)$site->getSettings()->get('meilisearch.tika.url', ''));
        $io->writeln('<info>Tika:</info>  ' . ($tikaUrl !== '' ? $tikaUrl : '(not configured)'));
        $io->newLine();

        $result = $this->textExtractor->extract($file, $site);
        $statusLabel = match ($result->status) {
            ExtractionResult::SUCCESS => '<info>✓ SUCCESS</info>',
            ExtractionResult::SKIPPED => '<comment>– SKIPPED</comment>',
            ExtractionResult::FAILED => '<fg=red>✗ FAILED</>',
            default => $result->status,
        };
        $io->writeln('<info>Status:</info> ' . $statusLabel . ($result->reason !== '' ? ' (' . $result->reason . ')' : ''));

        if ($result->status === ExtractionResult::SUCCESS) {
            $text = $result->text;
            $length = strlen($text);
            $io->writeln('<info>Length:</info> ' . number_format($length) . ' chars');
            $io->newLine();
            if ($full || $length <= self::PREVIEW_LENGTH) {
                $io->writeln($text);
            } else {
                $io->writeln(substr($text, 0, self::PREVIEW_LENGTH) . "\n…\n[truncated — use --full to see all " . number_format($length) . ' chars]');
            }
            return Command::SUCCESS;
        }

        return $result->status === ExtractionResult::FAILED ? Command::FAILURE : Command::SUCCESS;
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

    private function resolveFile(string $arg): ?File
    {
        try {
            if (ctype_digit($arg)) {
                $f = $this->resourceFactory->getFileObject((int)$arg);
                return $f instanceof File ? $f : null;
            }
            $f = $this->resourceFactory->getFileObjectFromCombinedIdentifier($arg);
            return $f instanceof File ? $f : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

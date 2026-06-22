<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Sweeps sys_file for rows whose underlying storage object no longer
 * exists. Flips `missing=1` on the dead ones so the FileSchemaProvider
 * (and any other consumer that filters on `missing=0`) skips them
 * without each row triggering an AWS-SDK retry loop at reindex time.
 *
 * Why this exists: on environments where a bucket has been pruned
 * or partially migrated, the sys_file table accumulates rows that
 * point to keys the storage no longer holds. Every reindex pass then
 * spends hours probing those dead keys before any News / KR / Pages
 * get a chance — see the meilisearch.indexing.skipFalForBrokenStorage
 * site setting, the emergency bypass that motivated this command.
 *
 * Recipe:
 *   1. Run this sweep (slow — drivers HEAD-probe each row, expect
 *      ~100-200 ms per file on a hot bucket, more on a cold one).
 *   2. Once dead rows are marked, switch
 *      meilisearch.indexing.skipFalForBrokenStorage back to false.
 *   3. Reindex — the SQL `WHERE missing = 0` clause in the file
 *      provider now naturally excludes the dead rows.
 *
 * Driver-side NoSuchKey warnings are suppressed during the sweep so
 * the TYPO3 log doesn't fill up with PHP user warnings (each dead
 * key would otherwise emit one).
 */
#[AsCommand(
    name: 'ws_meilisearch:sys-file-sweep',
    description: 'Probe every sys_file row for storage-side existence; flag the dead ones missing=1 so reindex skips them cheaply.'
)]
final class SysFileExistenceSweepCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Cap the number of rows probed (0 = all).', '0')
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'Restrict to a single storage uid.', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without setting missing=1.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'How many uids to UPDATE at a time.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(0, (int)$input->getOption('limit'));
        $storageFilter = max(0, (int)$input->getOption('storage'));
        $batchSize = max(50, (int)$input->getOption('batch-size'));
        $dryRun = (bool)$input->getOption('dry-run');

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->select('uid', 'storage', 'identifier')
            ->from('sys_file')
            ->where($qb->expr()->eq('missing', $qb->createNamedParameter(0, \PDO::PARAM_INT)));
        if ($storageFilter > 0) {
            $qb->andWhere($qb->expr()->eq('storage', $qb->createNamedParameter($storageFilter, \PDO::PARAM_INT)));
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $total = count($rows);
        $io->writeln(sprintf('Probing %d sys_file rows…', $total));

        // Suppress AWS-SDK NoSuchKey user-warnings that the S3 stream
        // wrapper emits on a missed file_exists / url_stat. They
        // arrive via trigger_error(E_USER_WARNING) — not exceptions —
        // and would otherwise spam the TYPO3 log with one entry per
        // dead row. We still capture the "did it exist?" signal from
        // the driver's return value.
        $previousHandler = set_error_handler(static function (int $errno, string $errstr): bool {
            if (str_contains($errstr, 'NoSuchKey') || str_contains($errstr, 'AWS HTTP error')) {
                return true; // swallow
            }
            return false; // let PHP handle anything unrelated
        }, E_USER_WARNING | E_WARNING);

        $deadUids = [];
        $stats = ['alive' => 0, 'dead' => 0, 'error' => 0];
        $progress = $io->createProgressBar($total);
        $progress->setRedrawFrequency(max(1, (int)($total / 200)));
        $progress->start();
        try {
            foreach ($rows as $row) {
                $storageUid = (int)$row['storage'];
                $identifier = (string)$row['identifier'];
                try {
                    $storage = $this->storageRepository->findByUid($storageUid);
                    if ($storage === null) {
                        $stats['error']++;
                    } elseif ($storage->getDriver()->fileExists($identifier)) {
                        $stats['alive']++;
                    } else {
                        $stats['dead']++;
                        $deadUids[] = (int)$row['uid'];
                    }
                } catch (\Throwable) {
                    // Driver threw — treat as dead. Better safe than
                    // leaving an unprobeable row to crash the reindex.
                    $stats['dead']++;
                    $deadUids[] = (int)$row['uid'];
                }
                $progress->advance();
            }
        } finally {
            $progress->finish();
            restore_error_handler();
        }
        $io->newLine(2);
        $io->writeln(sprintf(
            'Alive: %d / Dead: %d / Errored: %d',
            $stats['alive'],
            $stats['dead'],
            $stats['error'],
        ));

        if ($dryRun) {
            $io->note('Dry run — no rows updated.');
            return Command::SUCCESS;
        }
        if ($deadUids === []) {
            $io->success('No dead rows — sys_file is clean.');
            return Command::SUCCESS;
        }

        $now = time();
        $conn = $this->connectionPool->getConnectionForTable('sys_file');
        $updated = 0;
        foreach (array_chunk($deadUids, $batchSize) as $chunk) {
            $upd = $this->connectionPool->getQueryBuilderForTable('sys_file');
            $upd->update('sys_file')
                ->set('missing', 1)
                ->set('tstamp', $now)
                ->where($upd->expr()->in('uid', $upd->createNamedParameter($chunk, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)));
            $updated += $upd->executeStatement();
        }
        $io->success(sprintf('Flagged %d sys_file rows as missing.', $updated));
        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Updates;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\ChattyInterface;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Rename `tx_wsmeilisearch_helpdoc` → `tx_wsmeilisearch_knowledge_resource`
 * (and the legacy `help_type` column to `resource_type`) in place. The
 * TYPO3 schema migrator would otherwise leave the old table behind and
 * create an empty new one — wiping every imported record.
 *
 * Idempotent: runs only if the old table still exists. On Stage/Live
 * the wizard appears in Install Tool → Upgrade as soon as the new
 * extension version is installed.
 */
#[UpgradeWizard('wsMeilisearchRenameHelpDocTable')]
final class RenameHelpDocTableUpdate implements UpgradeWizardInterface, ChattyInterface
{
    private const OLD_TABLE = 'tx_wsmeilisearch_helpdoc';
    private const NEW_TABLE = 'tx_wsmeilisearch_knowledge_resource';
    private const OLD_COLUMN = 'help_type';
    private const NEW_COLUMN = 'resource_type';

    private ?OutputInterface $output = null;

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function getIdentifier(): string
    {
        return 'wsMeilisearchRenameHelpDocTable';
    }

    public function getTitle(): string
    {
        return 'EXT:ws_meilisearch: Rename help-docs table to knowledge_resource';
    }

    public function getDescription(): string
    {
        return 'Renames tx_wsmeilisearch_helpdoc to tx_wsmeilisearch_knowledge_resource '
            . 'and the help_type column to resource_type. The old table held imported '
            . 'DITA topics that are now reclassified as internal RAG-context resources '
            . '(hidden from FE search results + RAG citation list). Existing rows are '
            . 'kept in place — the wizard only renames the table and one column.';
    }

    public function getPrerequisites(): array
    {
        return [];
    }

    public function updateNecessary(): bool
    {
        return $this->oldTableExists();
    }

    public function executeUpdate(): bool
    {
        if (!$this->oldTableExists()) {
            return true;
        }
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::OLD_TABLE);
        $schemaManager = $connection->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        if (in_array(self::NEW_TABLE, $tables, true)) {
            // The new table exists already — that's only safe if it's empty;
            // otherwise we'd risk merging two divergent datasets.
            $newRowCount = (int)$connection->executeQuery('SELECT COUNT(*) FROM ' . self::NEW_TABLE)
                ->fetchOne();
            if ($newRowCount > 0) {
                $this->log(sprintf(
                    'Cannot rename: %s already has %d rows. Investigate manually.',
                    self::NEW_TABLE, $newRowCount,
                ));
                return false;
            }
            $connection->executeStatement('DROP TABLE ' . self::NEW_TABLE);
            $this->log('Dropped empty target table ' . self::NEW_TABLE . ' so the rename can proceed.');
        }

        $connection->executeStatement(sprintf(
            'RENAME TABLE %s TO %s',
            $connection->quoteIdentifier(self::OLD_TABLE),
            $connection->quoteIdentifier(self::NEW_TABLE),
        ));
        $this->log(sprintf('Renamed %s → %s.', self::OLD_TABLE, self::NEW_TABLE));

        $columns = array_map(static fn($c) => $c->getName(), $schemaManager->listTableColumns(self::NEW_TABLE));
        if (in_array(self::OLD_COLUMN, $columns, true) && !in_array(self::NEW_COLUMN, $columns, true)) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s CHANGE %s %s VARCHAR(32) NOT NULL DEFAULT %s',
                $connection->quoteIdentifier(self::NEW_TABLE),
                $connection->quoteIdentifier(self::OLD_COLUMN),
                $connection->quoteIdentifier(self::NEW_COLUMN),
                $connection->quote(''),
            ));
            $this->log(sprintf('Renamed column %s → %s.', self::OLD_COLUMN, self::NEW_COLUMN));
        }

        return true;
    }

    private function oldTableExists(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::OLD_TABLE);
        $tables = $connection->createSchemaManager()->listTableNames();
        return in_array(self::OLD_TABLE, $tables, true);
    }

    private function log(string $message): void
    {
        if ($this->output !== null) {
            $this->output->writeln($message);
        }
    }
}

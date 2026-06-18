<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Updates;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\ChattyInterface;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Soft-delete duplicate knowledge_resource rows that DITA-OT created
 * by re-bundling the same topic multiple times: identifiers ending in
 * `-N` or `_N` (e.g. `foo-1`, `foo_2`) whose unsuffixed base (`foo`)
 * already exists in the same language are content-identical artefacts.
 *
 * Idempotent — sets deleted=1 only on rows where the base sibling is
 * still present + active. The DitaOtImporter (since the same release)
 * skips these on insert, so once cleaned, no new ones appear.
 *
 * After this wizard runs, the next ws_meilisearch:reindex pushes the
 * deduplicated set; the legacy Meilisearch documents are evicted by
 * the index rebuild.
 */
#[UpgradeWizard('wsMeilisearchDeduplicateSuffixedResources')]
final class DeduplicateSuffixedKnowledgeResourcesUpdate implements UpgradeWizardInterface, ChattyInterface
{
    private const TABLE = 'tx_wsmeilisearch_knowledge_resource';
    private const SUFFIX_PATTERN = '[-_][0-9]+$';

    private ?OutputInterface $output = null;

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function getIdentifier(): string
    {
        return 'wsMeilisearchDeduplicateSuffixedResources';
    }

    public function getTitle(): string
    {
        return 'EXT:ws_meilisearch: Deduplicate knowledge_resource suffix variants';
    }

    public function getDescription(): string
    {
        return 'Soft-deletes knowledge_resource rows whose identifier ends in '
            . '-N / _N when an unsuffixed base sibling exists for the same '
            . 'language. These are DITA-OT artefacts created when a topic is '
            . 'referenced from multiple places in the map (byte-identical to '
            . 'the base). Run ws_meilisearch:reindex afterwards so Meilisearch '
            . 'drops the orphan documents and rebuilds the deduplicated set.';
    }

    public function getPrerequisites(): array
    {
        return [
            // Make sure the table rename ran first; otherwise the new name
            // doesn't exist yet and the query would fail.
            RenameHelpDocTableUpdate::class,
        ];
    }

    public function updateNecessary(): bool
    {
        try {
            return $this->countDuplicates() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function executeUpdate(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $qb = $connection->createQueryBuilder();

        // Find candidate uids whose suffixed identifier has an active base
        // sibling in the same language. SELECT then UPDATE — keeps the
        // statement portable across MySQL/MariaDB versions that disallow
        // self-referencing UPDATE…JOIN against the modified table.
        $rows = $qb->select('uid', 'identifier', 'sys_language_uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                'identifier REGEXP ' . $qb->createNamedParameter(self::SUFFIX_PATTERN),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $toDelete = [];
        foreach ($rows as $row) {
            $base = preg_replace('/[-_][0-9]+$/', '', (string)$row['identifier']);
            if ($base === '' || $base === $row['identifier']) {
                continue;
            }
            if ($this->baseExists($connection, $base, (int)$row['sys_language_uid'])) {
                $toDelete[] = (int)$row['uid'];
            }
        }

        if ($toDelete === []) {
            $this->log('No suffix duplicates to remove.');
            return true;
        }

        $now = time();
        $deleteQb = $connection->createQueryBuilder();
        $deleteQb->update(self::TABLE)
            ->set('deleted', '1', false)
            ->set('tstamp', (string)$now, false)
            ->where(
                $deleteQb->expr()->in('uid', $deleteQb->createNamedParameter($toDelete, Connection::PARAM_INT_ARRAY)),
            )
            ->executeStatement();

        $this->log(sprintf('Soft-deleted %d suffix-duplicate knowledge_resource rows.', count($toDelete)));
        $this->log('Run ws_meilisearch:reindex --rebuild to drop the orphan documents from Meilisearch.');
        return true;
    }

    private function countDuplicates(): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $qb = $connection->createQueryBuilder();
        $rows = $qb->select('identifier', 'sys_language_uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                'identifier REGEXP ' . $qb->createNamedParameter(self::SUFFIX_PATTERN),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $count = 0;
        foreach ($rows as $row) {
            $base = preg_replace('/[-_][0-9]+$/', '', (string)$row['identifier']);
            if ($base !== '' && $base !== $row['identifier']
                && $this->baseExists($connection, $base, (int)$row['sys_language_uid'])
            ) {
                $count++;
            }
        }
        return $count;
    }

    private function baseExists(Connection $connection, string $baseIdentifier, int $languageId): bool
    {
        $qb = $connection->createQueryBuilder();
        $row = $qb->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('identifier', $qb->createNamedParameter($baseIdentifier)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false;
    }

    private function log(string $message): void
    {
        if ($this->output !== null) {
            $this->output->writeln($message);
        }
    }
}

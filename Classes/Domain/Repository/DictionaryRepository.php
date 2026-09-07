<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Domain\Repository;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * The editorial half of the search dictionary (tx_wsmeilisearch_dictionary).
 *
 * Deliberately plain DBAL rather than Extbase: the rows are read while
 * building the Meilisearch index settings, which happens in CLI and in the
 * indexer — both contexts where Extbase's ConfigurationManager has no
 * request and blows up.
 *
 * Rows are keyed by (site_identifier, kind, term). An empty site_identifier
 * means "every site" — the usual case for a product vocabulary that is the
 * same across the whole installation.
 */
final class DictionaryRepository
{
    public const TABLE = 'tx_wsmeilisearch_dictionary';

    public const KIND_SYNONYM = 'synonym';
    public const KIND_WORD = 'word';
    public const KIND_STOPWORD = 'stopword';

    public const STATE_CANDIDATE = 'candidate';
    public const STATE_ACTIVE = 'active';
    public const STATE_REJECTED = 'rejected';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_LOG = 'log';
    public const SOURCE_TRANSLATION = 'translation';
    public const SOURCE_VOCABULARY = 'vocabulary';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Active synonym rows as Meilisearch expects them: term => replacements.
     *
     * @return array<string, list<string>>
     */
    public function activeSynonyms(string $siteIdentifier): array
    {
        $out = [];
        foreach ($this->activeRows($siteIdentifier, self::KIND_SYNONYM) as $row) {
            $term = mb_strtolower(trim((string)$row['term']));
            $replacements = $this->splitReplacements((string)($row['replacements'] ?? ''));
            if ($term !== '' && $replacements !== []) {
                $out[$term] = $replacements;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function activeWords(string $siteIdentifier): array
    {
        return $this->activeTerms($siteIdentifier, self::KIND_WORD);
    }

    /**
     * @return list<string>
     */
    public function activeStopWords(string $siteIdentifier): array
    {
        return $this->activeTerms($siteIdentifier, self::KIND_STOPWORD);
    }

    /**
     * Insert a mined candidate unless the very same term is already on
     * record in ANY state — an editor who rejected "objekt enebler" once
     * must not see it proposed again after the next mining run.
     *
     * @param list<string> $replacements
     * @return bool true when a new row was written
     */
    public function addCandidate(
        string $siteIdentifier,
        string $kind,
        string $term,
        array $replacements,
        string $source,
        string $evidence = '',
        int $hits = 0,
        int $storagePid = 0,
    ): bool {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return false;
        }
        if ($this->exists($siteIdentifier, $kind, $term)) {
            return false;
        }
        $now = time();
        $this->connection()->insert(self::TABLE, [
            'pid' => $storagePid,
            'crdate' => $now,
            'tstamp' => $now,
            'site_identifier' => substr($siteIdentifier, 0, 64),
            'kind' => substr($kind, 0, 16),
            'term' => substr($term, 0, 190),
            'replacements' => implode("\n", array_values(array_unique($replacements))),
            'state' => self::STATE_CANDIDATE,
            'source' => substr($source, 0, 24),
            'evidence' => substr($evidence, 0, 255),
            'hits' => max(0, $hits),
        ]);
        return true;
    }

    /**
     * Any row for this term, regardless of state — including hidden and
     * soft-deleted ones, because a deleted row is still a decision an
     * editor made.
     */
    public function exists(string $siteIdentifier, string $kind, string $term): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $count = $qb->count('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('kind', $qb->createNamedParameter($kind)),
                $qb->expr()->eq('term', $qb->createNamedParameter(mb_strtolower(trim($term)))),
                $qb->expr()->in(
                    'site_identifier',
                    $qb->createNamedParameter(['', $siteIdentifier], ArrayParameterType::STRING),
                ),
            )
            ->executeQuery()
            ->fetchOne();
        return (int)$count > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function candidates(string $siteIdentifier = '', int $limit = 100): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_CANDIDATE)),
                $qb->expr()->in(
                    'site_identifier',
                    $qb->createNamedParameter(['', $siteIdentifier], ArrayParameterType::STRING),
                ),
            )
            ->orderBy('hits', 'DESC')
            ->addOrderBy('crdate', 'DESC')
            ->setMaxResults($limit);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return list<string>
     */
    private function activeTerms(string $siteIdentifier, string $kind): array
    {
        $out = [];
        foreach ($this->activeRows($siteIdentifier, $kind) as $row) {
            $term = trim((string)$row['term']);
            if ($term !== '') {
                $out[] = $term;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function activeRows(string $siteIdentifier, string $kind): array
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll()
                ->add(new DeletedRestriction())
                ->add(new HiddenRestriction());
            $qb->select('term', 'replacements')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('kind', $qb->createNamedParameter($kind)),
                    $qb->expr()->eq('state', $qb->createNamedParameter(self::STATE_ACTIVE)),
                    $qb->expr()->in(
                        'site_identifier',
                        $qb->createNamedParameter(['', $siteIdentifier], ArrayParameterType::STRING),
                    ),
                )
                ->orderBy('term', 'ASC');
            return $qb->executeQuery()->fetchAllAssociative();
        } catch (\Throwable) {
            // Table not yet created (fresh install before the schema
            // analyzer ran). The YAML half of the dictionary must keep
            // working on its own.
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function splitReplacements(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $value = mb_strtolower(trim($part));
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}

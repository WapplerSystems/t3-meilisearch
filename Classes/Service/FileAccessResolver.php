<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Derives the `accessGroups` of a FAL file from the records that link it.
 *
 * `sys_file_metadata.fe_groups` is rarely maintained. Editors protect a
 * download by putting it on an access-restricted page — and the file then
 * surfaced to every anonymous visitor in search and as a chat citation,
 * because the index only looked at the metadata. On intewa.com that was
 * every partner document on the login-protected download page.
 *
 * Rules, all of them conservative (hide rather than leak):
 *
 *  - Only references a visitor could actually see count: the referencing
 *    record is not deleted, not hidden, not outside its start/end time, and
 *    its page plus every ancestor whose restriction extends to subpages is
 *    visible as well.
 *  - Within one reference every level must be satisfied (record fe_group
 *    AND page fe_group AND inherited ancestor fe_group). The search filter
 *    can only express "any of these groups", so the levels are intersected;
 *    a reference whose levels cannot be expressed that way does not count.
 *  - A file is public as soon as one visible reference is public. Otherwise
 *    it is visible to the union of the groups of its visible references.
 *  - A file that has references, none of them visible, is visible to nobody.
 *  - A file without any reference keeps the previous behaviour (metadata
 *    only), so a deliberately unlinked file library is not hidden.
 *  - Groups from sys_file_metadata.fe_groups still apply on top (AND).
 *
 * Known gaps: changing a page's fe_group does not re-index the files on it
 * (the next file reindex picks it up), and files shown through a FOLDER-based
 * sys_file_collection are not in sys_file_reference at all, so they count as
 * unreferenced and keep their metadata access only.
 */
final class FileAccessResolver
{
    /**
     * accessGroups value no visitor carries — TYPO3 itself only uses -1
     * (anonymous) and -2 (any logged-in user), group uids are positive.
     */
    public const NOBODY = -99;

    private const ANY_LOGIN = -2;

    /** @var array<int,list<list<int>>>|null fileUid => one requirement set per visible reference */
    private ?array $referenceRequirements = null;

    /** @var array<int,true>|null fileUids with at least one reference, visible or not */
    private ?array $referencedFiles = null;

    /** @var array<int,array{pid:int,hidden:int,fe_group:string,extendToSubpages:int,starttime:int,endtime:int}>|null */
    private ?array $pages = null;

    /** @var array<int,list<list<int>>|null> pageUid => requirement sets, or null when the page is invisible */
    private array $pageCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int> $metadataGroups parsed sys_file_metadata.fe_groups
     * @return list<int> accessGroups for the index document (empty = public)
     */
    public function resolve(int $fileUid, array $metadataGroups = []): array
    {
        $this->load();

        if (!isset($this->referencedFiles[$fileUid])) {
            return $metadataGroups;
        }

        $derived = null; // null = no visible reference yet
        foreach ($this->referenceRequirements[$fileUid] ?? [] as $sets) {
            $groups = self::intersectAll($sets);
            if ($groups === null) {
                // Public reference: nothing below can make the file narrower.
                $derived = [];
                break;
            }
            if ($groups === []) {
                continue;
            }
            $derived = array_values(array_unique(array_merge($derived ?? [], $groups)));
        }

        if ($derived === null) {
            return [self::NOBODY];
        }
        if ($metadataGroups === []) {
            sort($derived);
            return $derived;
        }
        if ($derived === []) {
            return $metadataGroups;
        }
        $combined = self::intersectAll([$metadataGroups, $derived]);
        return $combined === null || $combined === [] ? [self::NOBODY] : $combined;
    }

    /**
     * Forget everything — called when references or page access change
     * within one request (DataHandler hook) or between reindex runs.
     */
    public function reset(): void
    {
        $this->referenceRequirements = null;
        $this->referencedFiles = null;
        $this->pages = null;
        $this->pageCache = [];
    }

    /**
     * AND of several "any of these groups" sets, expressed as one such set.
     * Returns null when there is no restriction at all (public), an empty
     * list when no single group list can express the conjunction.
     *
     * @param list<list<int>> $sets
     * @return list<int>|null
     */
    private static function intersectAll(array $sets): ?array
    {
        $sets = array_values(array_filter($sets, static fn (array $s): bool => $s !== []));
        if ($sets === []) {
            return null;
        }
        $result = array_shift($sets);
        foreach ($sets as $set) {
            $result = self::intersectTwo($result, $set);
            if ($result === []) {
                return [];
            }
        }
        sort($result);
        return array_values(array_unique($result));
    }

    /**
     * -2 ("any logged-in user") is satisfied by every positive group, so it
     * narrows to the other side's positive groups instead of vanishing;
     * -1 ("only anonymous") contradicts every positive group.
     *
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function intersectTwo(array $a, array $b): array
    {
        $result = array_values(array_intersect($a, $b));
        $positiveA = array_filter($a, static fn (int $g): bool => $g > 0);
        $positiveB = array_filter($b, static fn (int $g): bool => $g > 0);
        if (in_array(self::ANY_LOGIN, $a, true)) {
            $result = array_merge($result, $positiveB);
        }
        if (in_array(self::ANY_LOGIN, $b, true)) {
            $result = array_merge($result, $positiveA);
        }
        return array_values(array_unique($result));
    }

    private function load(): void
    {
        if ($this->referenceRequirements !== null) {
            return;
        }
        $this->referenceRequirements = [];
        $this->referencedFiles = [];

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid_local', 'tablenames', 'uid_foreign', 'hidden')
            ->from('sys_file_reference')
            ->where($qb->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchAllAssociative();

        $byTable = [];
        foreach ($rows as $row) {
            $fileUid = (int)$row['uid_local'];
            // A hidden reference still marks the file as "linked somewhere" —
            // it just grants nobody access.
            $this->referencedFiles[$fileUid] = true;
            if ((int)$row['hidden'] !== 0) {
                continue;
            }
            $byTable[(string)$row['tablenames']][(int)$row['uid_foreign']][] = $fileUid;
        }

        foreach ($byTable as $table => $filesByRecord) {
            foreach ($this->recordRequirements($table, array_keys($filesByRecord)) as $recordUid => $sets) {
                foreach ($filesByRecord[$recordUid] as $fileUid) {
                    $this->referenceRequirements[$fileUid][] = $sets;
                }
            }
        }
    }

    /**
     * Requirement sets per visible record of $table; invisible records and
     * records outside any page are left out.
     *
     * @param list<int> $uids
     * @return array<int,list<list<int>>>
     */
    private function recordRequirements(string $table, array $uids): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? null;
        if (!is_array($ctrl) || $uids === []) {
            return [];
        }
        $enable = (array)($ctrl['enablecolumns'] ?? []);
        $fields = ['uid', 'pid'];
        foreach (['disabled', 'fe_group', 'starttime', 'endtime'] as $key) {
            if (isset($enable[$key])) {
                $fields[] = $enable[$key];
            }
        }
        $deleteField = (string)($ctrl['delete'] ?? '');

        $result = [];
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        foreach (array_chunk($uids, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $qb->select(...array_unique($fields))
                ->from($table)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            if ($deleteField !== '') {
                $qb->andWhere($qb->expr()->eq($deleteField, 0));
            }
            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                if (isset($enable['disabled']) && (int)$row[$enable['disabled']] !== 0) {
                    continue;
                }
                if (isset($enable['starttime']) && (int)$row[$enable['starttime']] > $now) {
                    continue;
                }
                if (isset($enable['endtime']) && (int)$row[$enable['endtime']] > 0 && (int)$row[$enable['endtime']] <= $now) {
                    continue;
                }
                $pageUid = $table === 'pages' ? (int)$row['uid'] : (int)$row['pid'];
                $pageSets = $this->pageRequirements($pageUid, $table === 'pages');
                if ($pageSets === null) {
                    continue;
                }
                $sets = $pageSets;
                if ($table !== 'pages' && isset($enable['fe_group'])) {
                    $sets[] = self::parseGroups((string)$row[$enable['fe_group']]);
                }
                $result[(int)$row['uid']] = $sets;
            }
        }
        return $result;
    }

    /**
     * Requirement sets for content on $pageUid: the page's own fe_group plus
     * every ancestor's fe_group that extends to subpages. Null when the page
     * or a hiding ancestor makes the content invisible.
     *
     * @return list<list<int>>|null
     */
    private function pageRequirements(int $pageUid, bool $recordIsThePage = false): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }
        if (array_key_exists($pageUid, $this->pageCache)) {
            return $this->pageCache[$pageUid];
        }
        $this->loadPages();
        $sets = [];
        $uid = $pageUid;
        $own = true;
        $guard = 0;
        while ($uid > 0 && $guard++ < 100) {
            $page = $this->pages[$uid] ?? null;
            if ($page === null) {
                return $this->pageCache[$pageUid] = null;
            }
            $applies = $own || $page['extendToSubpages'] === 1;
            if ($applies) {
                $now = $GLOBALS['EXEC_TIME'] ?? time();
                $timedOut = ($page['starttime'] > $now) || ($page['endtime'] > 0 && $page['endtime'] <= $now);
                if ($page['hidden'] === 1 || $timedOut) {
                    return $this->pageCache[$pageUid] = null;
                }
                $sets[] = self::parseGroups($page['fe_group']);
            }
            $own = false;
            $uid = $page['pid'];
        }
        return $this->pageCache[$pageUid] = $sets;
    }

    private function loadPages(): void
    {
        if ($this->pages !== null) {
            return;
        }
        $this->pages = [];
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'pid', 'hidden', 'fe_group', 'extendToSubpages', 'starttime', 'endtime')
            ->from('pages')
            ->where($qb->expr()->eq('deleted', 0), $qb->expr()->eq('sys_language_uid', 0))
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $this->pages[(int)$row['uid']] = [
                'pid' => (int)$row['pid'],
                'hidden' => (int)$row['hidden'],
                'fe_group' => (string)$row['fe_group'],
                'extendToSubpages' => (int)$row['extendToSubpages'],
                'starttime' => (int)$row['starttime'],
                'endtime' => (int)$row['endtime'],
            ];
        }
    }

    /**
     * @return list<int>
     */
    private static function parseGroups(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0') {
            return [];
        }
        $ids = array_map(static fn (string $g): int => (int)trim($g), explode(',', $raw));
        return array_values(array_unique(array_filter($ids, static fn (int $g): bool => $g !== 0)));
    }
}

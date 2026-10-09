<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Whether a page may (still) be in the search index.
 *
 * Pages reach the index through EXT:index, which adds and updates documents
 * but never removes one: a page that is hidden, deleted or switched to "not
 * searchable" afterwards stays in the index — and the chat goes on citing it
 * as a source, link to a 404 included. Measured on linear.eu in 10/2026: a
 * campaign page hidden two days earlier and an editor's test page, both still
 * answered from. This class is the single place that decides, used by the
 * prune command for what is already in the index and by the page listener for
 * what would come in.
 *
 * Reads the database without the default restrictions on purpose: the point
 * is to see the hidden and deleted rows the frontend would hide.
 */
final class PageIndexEligibility
{
    public const REASON_MISSING = 'missing';
    public const REASON_DELETED = 'deleted';
    public const REASON_HIDDEN = 'hidden';
    public const REASON_TIME = 'outside start/end time';
    public const REASON_NO_SEARCH = 'no_search';
    public const REASON_DOKTYPE = 'not a content page';
    public const REASON_TRANSLATION = 'translation hidden or deleted';
    public const REASON_PARENT_HIDDEN = 'hidden parent (extendToSubpages)';
    public const REASON_EXCLUDED_TREE = 'excluded page tree';

    /**
     * Folder, spacer, backend user section, and the recycler of older
     * installations (255, no constant since v14): never a search result.
     */
    private const NON_CONTENT_DOKTYPES = [
        PageRepository::DOKTYPE_SYSFOLDER,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_BE_USER_SECTION,
        255,
    ];

    /** @var array<int, array<string,mixed>|null> */
    private array $rows = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
    ) {}

    /**
     * Drop the page rows read so far. The class is a shared service and lives
     * as long as a messenger worker does; without this a page hidden after
     * the first lookup would keep its old state for the worker's lifetime.
     */
    public function forgetCache(): void
    {
        $this->rows = [];
    }

    /**
     * Page trees (by root uid) that never belong in the index, from
     * meilisearch.index.excludedPageTrees — e.g. campaign landing pages,
     * which carry a form and a sales pitch but nothing a visitor searches for.
     *
     * @return list<int>
     */
    public function excludedTrees(Site $site): array
    {
        $value = $site->getSettings()->get('meilisearch.index.excludedPageTrees', []);
        if (!is_array($value)) {
            $value = explode(',', (string)$value);
        }

        return array_values(array_filter(array_map('intval', $value), static fn (int $uid): bool => $uid > 0));
    }

    /**
     * Null when the page may be indexed in that language, otherwise the
     * reason (one of the REASON_* constants).
     */
    public function ineligibleReason(int $pageUid, int $languageId, Site $site): ?string
    {
        $row = $this->row($pageUid);
        if ($row === null) {
            return self::REASON_MISSING;
        }
        $reason = $this->rowReason($row, true);
        if ($reason !== null) {
            return $reason;
        }
        // A missing translation is not a reason: in a fallback language the
        // page renders with the default content and is a valid result there.
        // A translation that exists but is hidden or deleted is one.
        if ($languageId > 0) {
            $overlay = $this->overlay($pageUid, $languageId);
            if ($overlay !== null && $this->rowReason($overlay, false) !== null) {
                return self::REASON_TRANSLATION;
            }
        }

        $excluded = $this->excludedTrees($site);
        $pid = (int)$row['pid'];
        // Walk up the rootline by pid; RootlineUtility refuses hidden pages,
        // which are exactly the ones this has to see.
        for ($depth = 0; $pid > 0 && $depth < 64; $depth++) {
            if (in_array($pid, $excluded, true)) {
                return self::REASON_EXCLUDED_TREE;
            }
            $parent = $this->row($pid);
            if ($parent === null) {
                break;
            }
            if ((int)$parent['deleted'] === 1) {
                return self::REASON_DELETED;
            }
            if ((int)$parent['hidden'] === 1 && (int)$parent['extendToSubpages'] === 1) {
                return self::REASON_PARENT_HIDDEN;
            }
            $pid = (int)$parent['pid'];
        }
        if (in_array($pageUid, $excluded, true)) {
            return self::REASON_EXCLUDED_TREE;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowReason(array $row, bool $default): ?string
    {
        if ((int)$row['deleted'] === 1) {
            return self::REASON_DELETED;
        }
        if ((int)$row['hidden'] === 1) {
            return self::REASON_HIDDEN;
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $start = (int)$row['starttime'];
        $end = (int)$row['endtime'];
        if (($start > 0 && $start > $now) || ($end > 0 && $end <= $now)) {
            return self::REASON_TIME;
        }
        if ($default) {
            if ((int)$row['no_search'] === 1) {
                return self::REASON_NO_SEARCH;
            }
            if (in_array((int)$row['doktype'], self::NON_CONTENT_DOKTYPES, true)) {
                return self::REASON_DOKTYPE;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(int $uid): ?array
    {
        if (!array_key_exists($uid, $this->rows)) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();
            $row = $qb->select('uid', 'pid', 'deleted', 'hidden', 'starttime', 'endtime', 'no_search', 'doktype', 'extendToSubpages')
                ->from('pages')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
            $this->rows[$uid] = $row === false ? null : $row;
        }

        return $this->rows[$uid];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function overlay(int $pageUid, int $languageId): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'deleted', 'hidden', 'starttime', 'endtime')
            ->from('pages')
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, Connection::PARAM_INT)),
            )
            ->orderBy('deleted')
            ->addOrderBy('hidden')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows[0] ?? null;
    }
}

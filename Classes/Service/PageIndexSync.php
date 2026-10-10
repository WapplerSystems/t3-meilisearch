<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Removes a page's documents from the index the moment a backend action
 * makes it unfindable: delete, hide, a start/end time that excludes now,
 * "not searchable", a move into an excluded page tree.
 *
 * Adding is EXT:index's job — its DataHandler hook queues the page for
 * rendering after every save, so un-hiding a page brings it back. Removing
 * it is not: the hook looks the page up with the frontend restrictions, finds
 * nothing for a hidden or deleted page, and does nothing. The document
 * stayed, and the chat kept citing a page that answers with a 404.
 *
 * Runs synchronously inside the DataHandler call, so the document is gone
 * before the editor's save returns. Works on default-language uids and
 * checks every language variant (`pages-{uid}`, `pages-{uid}-l{N}`).
 */
final class PageIndexSync implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Upper bound for a subtree walk; a delete of a whole site section stays bounded. */
    private const MAX_SUBTREE_PAGES = 5000;

    public function __construct(
        private readonly PageIndexEligibility $eligibility,
        private readonly SearchEngineFactory $engineFactory,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param int $uid any pages uid — a translation is mapped to its default page
     * @param bool $withSubtree also check every page below it (delete, move,
     *                          a change on a page that extends to subpages)
     */
    public function sync(int $uid, bool $withSubtree): void
    {
        $row = $this->row($uid);
        if ($row === null) {
            return;
        }
        $pageUid = (int)$row['l10n_parent'] > 0 ? (int)$row['l10n_parent'] : $uid;
        $withSubtree = $withSubtree || (int)($this->row($pageUid)['extendToSubpages'] ?? 0) === 1;

        $site = $this->resolveSite($pageUid);
        if (!$site instanceof Site) {
            return;
        }
        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return;
        }

        $pages = [$pageUid];
        if ($withSubtree) {
            $pages = array_merge($pages, $this->descendants($pageUid));
        }
        $languageIds = array_map(
            static fn ($language): int => $language->getLanguageId(),
            $site->getAllLanguages(),
        );

        $this->eligibility->forgetCache();
        $remove = [];
        foreach ($pages as $page) {
            foreach ($languageIds as $languageId) {
                if ($this->eligibility->ineligibleReason($page, $languageId, $site) !== null) {
                    $remove[] = 'pages-' . $page . ($languageId > 0 ? '-l' . $languageId : '');
                }
            }
        }
        if ($remove === []) {
            return;
        }

        try {
            // Ids that are not in the index are ignored by Meilisearch, so a
            // page that never was indexed costs nothing but this one call.
            $client->index($this->engineFactory->getIndexName($site))->deleteDocuments($remove);
        } catch (\Throwable $e) {
            // Never fail the editor's save over the index; the nightly
            // ws_meilisearch:index-prune-pages catches what is left.
            $this->logger?->warning('Removing page documents failed: {msg}', ['msg' => $e->getMessage(), 'ids' => $remove]);
        }
    }

    /**
     * @return list<int> default-language uids below $uid, deleted ones included
     */
    private function descendants(int $uid): array
    {
        $found = [];
        $parents = [$uid];
        while ($parents !== [] && count($found) < self::MAX_SUBTREE_PAGES) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();
            $children = $qb->select('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->in('pid', $qb->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', 0),
                )
                ->executeQuery()
                ->fetchFirstColumn();
            $parents = array_map('intval', $children);
            array_push($found, ...$parents);
        }

        return array_slice($found, 0, self::MAX_SUBTREE_PAGES);
    }

    /**
     * The page's site. A deleted page has no rootline any more, so the lookup
     * walks up the pid chain until a page the site finder accepts.
     */
    private function resolveSite(int $uid): ?Site
    {
        for ($depth = 0; $uid > 0 && $depth < 64; $depth++) {
            try {
                return $this->siteFinder->getSiteByPageId($uid);
            } catch (\Throwable) {
                $uid = (int)($this->row($uid)['pid'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid', 'pid', 'l10n_parent', 'extendToSubpages')
            ->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}

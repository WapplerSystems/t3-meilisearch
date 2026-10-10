<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\Repository;

use Lochmueller\Index\Domain\Repository\PagesRepository;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;

/**
 * EXT:index's pages repository, minus the crash on a deleted page.
 *
 * After a page is deleted, DataHandler's cache-clear hook hands its uid to
 * EXT:index (DataHandlerUpdateHook::clearCacheCmd), which resolves the
 * rootline to find the indexing configuration — and RootlineUtility throws
 * "Broken rootline" for a deleted page. The delete itself is already done,
 * but the backend request ends in an exception (seen with lochmueller/index
 * 2.2.0, still the same code in 2.5.0). A deleted page has no configuration
 * and nothing to index, so an empty rootline is the right answer; removing
 * its documents is PageIndexSync's job.
 *
 * Swapped in via Configuration/Services.yaml.
 */
class TolerantPagesRepository extends PagesRepository
{
    public function getRootline(int $pageUid): array
    {
        try {
            return parent::getRootline($pageUid);
        } catch (PageNotFoundException) {
            return [];
        }
    }
}

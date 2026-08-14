<?php

declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Integration\ExtIndex\EventListener;

use Lochmueller\Index\Event\ContentType\HandleContentTypeEvent;
use Lochmueller\Index\Indexing\Database\ContentIndexing;
use Lochmueller\Index\Traversing\RecordSelection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Record;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Indexes the children of container content elements.
 *
 * EXT:index skips every record with `tx_container_parent > 0` when it
 * collects the elements of a page (NonContainerElementsRestrictionContainer)
 * and expects its own ContainerContentType to pull them back in. That one
 * only recognises record types starting with `container_`, so containers
 * named differently — `t3bs_fluidrow`, `t3bs_column`, `t3bs_accordion_item`,
 * anything a site defines itself — keep their children out of the index.
 * On a container-built site that silently drops most of the page: for a
 * typical T3Bootstrap page 51 of 63 content elements are children.
 *
 * EXT:index dispatches HandleContentTypeEvent for every record it indexes,
 * which is our way in: whatever the record type is called, if the record has
 * children, we index them into the same document. Nested containers resolve
 * themselves, because indexing a child dispatches the event again.
 */
final class ContainerChildrenListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Containers nest a handful of levels at most (row → column → accordion →
     * item). The limit only exists so a corrupted `tx_container_parent`
     * pointing at itself cannot spin the indexer forever.
     */
    private const MAX_DEPTH = 10;

    private int $depth = 0;

    /**
     * Children of the page currently being indexed, grouped by parent uid.
     * The event fires for every single content element, so asking the
     * database per element would add one query per element (60+ on a
     * container-built page); one query per page and language does instead.
     */
    private ?string $cacheKey = null;

    /** @var array<int, list<Record>> */
    private array $childrenByParent = [];

    public function __construct(
        private readonly RecordSelection $recordSelection,
        private readonly ContentIndexing $contentIndexing,
    ) {}

    #[AsEventListener('ws-meilisearch-ext-index-container-children')]
    public function __invoke(HandleContentTypeEvent $event): void
    {
        if (!ExtensionManagementUtility::isLoaded('container')) {
            return;
        }
        if ($this->depth >= self::MAX_DEPTH) {
            $this->logger?->warning('Container nesting deeper than {max} levels at content element {uid} — not descending further', [
                'max' => self::MAX_DEPTH,
                'uid' => $this->recordUid($event->record),
            ]);
            return;
        }

        $uid = $this->recordUid($event->record);
        $pid = $this->recordPid($event->record);
        if ($uid <= 0 || $pid <= 0) {
            return;
        }

        // EXT:index's own ContainerContentType already descended for record
        // types it knows (`container_*`) — descending again would index every
        // child twice.
        if (str_starts_with((string)$event->record->getRecordType(), 'container_')) {
            return;
        }

        $children = $this->childrenOf($uid, $pid, $event->record->getLanguageId() ?? 0);
        if ($children === []) {
            return;
        }

        $this->depth++;
        try {
            foreach ($children as $child) {
                if ($this->recordUid($child) === $uid) {
                    // Self-referencing tx_container_parent — skip rather than recurse.
                    continue;
                }
                // The separator keeps the last word of one child from being
                // glued to the first word of the next once the markup is
                // stripped (see HtmlToText).
                $event->dto->content .= ' ';
                $this->contentIndexing->addContent($child, $event->dto);
            }
        } finally {
            $this->depth--;
        }
    }

    /**
     * @return list<Record>
     */
    private function childrenOf(int $parentUid, int $pid, int $language): array
    {
        $key = $pid . '-' . $language;
        if ($this->cacheKey !== $key) {
            $this->childrenByParent = [];
            $records = $this->recordSelection->findRecordsOnPage(
                'tt_content',
                [$pid],
                $language,
                // No NonContainerElements restriction — we are after exactly
                // the records EXT:index leaves out at page level.
                [FrontendRestrictionContainer::class],
            );
            foreach ($records as $record) {
                $parent = $this->containerParent($record);
                if ($parent > 0) {
                    $this->childrenByParent[$parent][] = $record;
                }
            }
            $this->cacheKey = $key;
        }

        return $this->childrenByParent[$parentUid] ?? [];
    }

    private function containerParent(Record $record): int
    {
        try {
            return (int)$record->get('tx_container_parent');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function recordUid(Record $record): int
    {
        try {
            return (int)$record->get('uid');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function recordPid(Record $record): int
    {
        try {
            return (int)$record->get('pid');
        } catch (\Throwable) {
            return 0;
        }
    }
}

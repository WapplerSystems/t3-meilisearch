<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\DataHandling;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileCopiedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMetaDataUpdatedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMovedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileRemovedFromIndexEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;

/**
 * Storage-level FAL events. DataHandler covers BE form workflows
 * (sys_file_metadata edits, file delete via list module); these
 * cover the rest of the surface — file uploads via the FAL browser,
 * drag&drop, programmatic ResourceStorage calls, file replace,
 * rename / move between folders, content rewrite.
 *
 * All listeners delegate to FileLifecycleHandler so the cross-site
 * reindex/remove logic stays in one place.
 */
final class FalEventListener
{
    public function __construct(
        private readonly FileLifecycleHandler $fileLifecycle,
    ) {}

    #[AsEventListener('ws_meilisearch/after-file-added')]
    public function onFileAdded(AfterFileAddedEvent $event): void
    {
        $this->fileLifecycle->reindex($this->fileUid($event->getFile()));
    }

    #[AsEventListener('ws_meilisearch/after-file-deleted')]
    public function onFileDeleted(AfterFileDeletedEvent $event): void
    {
        $this->fileLifecycle->remove($this->fileUid($event->getFile()));
    }

    #[AsEventListener('ws_meilisearch/after-file-renamed')]
    public function onFileRenamed(AfterFileRenamedEvent $event): void
    {
        // publicUrl + filename change → reindex to refresh the doc.
        $this->fileLifecycle->reindex($this->fileUid($event->getFile()));
    }

    #[AsEventListener('ws_meilisearch/after-file-moved')]
    public function onFileMoved(AfterFileMovedEvent $event): void
    {
        // publicUrl change → reindex.
        $this->fileLifecycle->reindex($this->fileUid($event->getFile()));
    }

    #[AsEventListener('ws_meilisearch/after-file-contents-set')]
    public function onFileContentsSet(AfterFileContentsSetEvent $event): void
    {
        // Body changed → re-extract via Tika on reindex.
        $this->fileLifecycle->reindex($this->fileUid($event->getFile()));
    }

    #[AsEventListener('ws_meilisearch/after-file-replaced')]
    public function onFileReplaced(AfterFileReplacedEvent $event): void
    {
        $this->fileLifecycle->reindex($this->fileUid($event->getFile()));
    }

    #[AsEventListener('ws_meilisearch/after-file-copied')]
    public function onFileCopied(AfterFileCopiedEvent $event): void
    {
        // The copy is a new sys_file row with its own uid; index it.
        $new = $event->getNewFile();
        if ($new !== null) {
            $this->fileLifecycle->reindex($this->fileUid($new));
        }
    }

    #[AsEventListener('ws_meilisearch/after-file-metadata-updated')]
    public function onMetaDataUpdated(AfterFileMetaDataUpdatedEvent $event): void
    {
        // Fires alongside the DataHandler hook for sys_file_metadata
        // edits, but also from non-DataHandler paths (e.g. CLI imports
        // that rewrite metadata via the MetaDataRepository directly).
        // Idempotent — running both paths just reindexes the same row.
        $this->fileLifecycle->reindex($event->getFileUid());
    }

    #[AsEventListener('ws_meilisearch/after-file-removed-from-index')]
    public function onFileRemovedFromIndex(AfterFileRemovedFromIndexEvent $event): void
    {
        // sys_file row removed (e.g. by the FAL indexer marking an
        // orphan dead). The file is unreachable now, so any Meilisearch
        // doc for it should disappear too.
        $this->fileLifecycle->remove($event->getFileUid());
    }

    private function fileUid(\TYPO3\CMS\Core\Resource\FileInterface $file): int
    {
        // ProcessedFile and AbstractFile both expose getUid(); type the
        // cast cleanly here so callers don't have to.
        return (int)$file->getUid();
    }
}

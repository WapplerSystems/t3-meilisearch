<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use WapplerSystems\Meilisearch\Domain\Schema\FilePassageSchemaProvider;
use WapplerSystems\Meilisearch\Event\RagCitationLabelsEvent;

/**
 * Cites a file passage as "Manual (p. 30)" instead of the bare file title.
 *
 * The page goes in as the qualifier, so several pages of one manual collapse
 * into "[Manual (p. 30, p. 31)]" with one link per page; each link carries
 * `#page=N` from the passage's uri and opens the PDF on that page.
 */
final class PassageCitationLabels
{
    private const LLL = 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang.xlf:rag.citation.page';

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    #[AsEventListener('ws_meilisearch/passage-citation-labels')]
    public function __invoke(RagCitationLabelsEvent $event): void
    {
        $pattern = null;
        foreach ($event->getSources() as $source) {
            if (($source['type'] ?? '') !== FilePassageSchemaProvider::TYPE) {
                continue;
            }
            $page = (int)($source['page'] ?? 0);
            $title = trim((string)($source['title'] ?? ''));
            if ($page <= 0 || $title === '') {
                continue;
            }
            $pattern ??= $this->pagePattern($event);
            $event->setLabel((string)($source['id'] ?? ''), $title, sprintf($pattern, $page));
        }
    }

    private function pagePattern(RagCitationLabelsEvent $event): string
    {
        try {
            $languageId = $event->getLanguageId();
            $languageService = $languageId !== null
                ? $this->languageServiceFactory->createFromSiteLanguage($event->getSite()->getLanguageById($languageId))
                : $this->languageServiceFactory->createFromSiteLanguage($event->getSite()->getDefaultLanguage());
            $pattern = $languageService->sL(self::LLL);
        } catch (\Throwable) {
            $pattern = '';
        }
        return str_contains($pattern, '%d') ? $pattern : 'p. %d';
    }
}

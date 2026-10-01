<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module "Chatbot-Wissen" for maintaining hand-written AI chat knowledge.
 *
 * Records are stored on the site root page and therefore are site-scoped. All
 * writes are deliberately routed through FormEngine/DataHandler so the existing
 * indexing hook fires, permissions and validation apply, and editors get the
 * regular editing/history experience.
 */
#[AsController]
final class KnowledgeEntryController
{
    private const TABLE = 'tx_wsmeilisearch_knowledge_entry';
    private const ROUTE = 'site_wsmeilisearch_knowledge';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $params = array_merge(
            (array)$request->getQueryParams(),
            // The filter form posts: a GET form would drop the module URL's
            // query string, and with it the route token.
            (array)($request->getParsedBody() ?? [])
        );

        $action = (string)($params['action'] ?? '');

        return match ($action) {
            'new' => $this->newAction($params),
            'edit' => $this->editAction($params),
            'toggle' => $this->toggleAction($params),
            'delete' => $this->deleteAction($params),
            default => $this->listAction($request, $params),
        };
    }

    private function listAction(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Chatbot-Wissen');

        $canSelect = $this->hasTableSelectPermission();
        $canModify = $this->hasTableModifyPermission();

        if (!$canSelect) {
            $this->addFlash(
                'Sie haben keine Berechtigung, Wissenseinträge zu sehen.',
                ContextualFeedbackSeverity::ERROR
            );
        }

        $offeredSites = $this->offeredSites();
        if ($offeredSites === []) {
            $moduleTemplate->assignMultiple([
                'noSites' => true,
                'sites' => [],
                'siteIdentifier' => '',
                'languages' => [],
                'filterLanguage' => '',
                'q' => '',
                'state' => '',
                'rows' => [],
                'total' => 0,
                'limitReached' => false,
                'canModify' => false,
                'newUrl' => '',
                'listUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
            ]);

            return $moduleTemplate->renderResponse('Backend/Knowledge/Index');
        }

        $siteIdentifier = (string)($params['site'] ?? '');
        if ($siteIdentifier === '' || !isset($offeredSites[$siteIdentifier])) {
            $site = reset($offeredSites);
            $siteIdentifier = $site->getIdentifier();
        } else {
            $site = $offeredSites[$siteIdentifier];
        }

        $language = (string)($params['language'] ?? '');
        if ($language !== '' && !ctype_digit($language)) {
            $language = '';
        }

        $q = trim((string)($params['q'] ?? ''));
        $q = mb_substr($q, 0, 200);

        $state = (string)($params['state'] ?? '');
        if (!in_array($state, ['', 'visible', 'hidden'], true)) {
            $state = '';
        }

        $rows = [];
        $total = 0;
        $limitReached = false;

        if ($canSelect) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction());

            $queryBuilder
                ->select(
                    'uid',
                    'pid',
                    'title',
                    'body',
                    'keywords',
                    'notes',
                    'hidden',
                    'starttime',
                    'endtime',
                    'tstamp',
                    'sys_language_uid',
                    'source_conversation'
                )
                ->from(self::TABLE)
                ->where($queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($site->getRootPageId(), Connection::PARAM_INT)
                ));

            if ($language !== '') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter((int)$language, Connection::PARAM_INT)
                ));
            }

            if ($q !== '') {
                $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
                $searchLike = '%' . $connection->escapeLikeWildcards($q) . '%';

                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->like('title', $queryBuilder->createNamedParameter($searchLike)),
                        $queryBuilder->expr()->like('body', $queryBuilder->createNamedParameter($searchLike)),
                        $queryBuilder->expr()->like('keywords', $queryBuilder->createNamedParameter($searchLike)),
                    )
                );
            }

            if ($state === 'visible') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('hidden', 0));
            } elseif ($state === 'hidden') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('hidden', 1));
            }

            $queryBuilder
                ->orderBy('title', 'ASC')
                ->setMaxResults(500);

            $result = $queryBuilder->executeQuery();
            $now = time();

            while ($row = $result->fetchAssociative()) {
                $languageUid = (int)$row['sys_language_uid'];
                $languageTitle = '#' . $languageUid;
                $languageFlag = '';

                try {
                    $siteLanguage = $site->getLanguageById($languageUid);
                    $languageTitle = $siteLanguage->getTitle();
                    $languageFlag = $siteLanguage->getFlagIdentifier();
                } catch (\Throwable) {
                    // Keep fallback when language does not exist.
                }

                $returnParams = [
                    'site' => $siteIdentifier,
                    'returnUrl' => $this->listUrl($siteIdentifier, $language, $q, $state),
                ];

                $editParams = $returnParams;
                $editParams['action'] = 'edit';
                $editParams['uid'] = (int)$row['uid'];

                $toggleParams = $returnParams;
                $toggleParams['action'] = 'toggle';
                $toggleParams['uid'] = (int)$row['uid'];

                $deleteParams = $returnParams;
                $deleteParams['action'] = 'delete';
                $deleteParams['uid'] = (int)$row['uid'];

                $rows[] = [
                    'uid' => (int)$row['uid'],
                    'title' => (string)$row['title'],
                    'excerpt' => mb_substr(trim((string)$row['body']), 0, 200),
                    'languageTitle' => $languageTitle,
                    'languageFlag' => $languageFlag,
                    'hidden' => (bool)$row['hidden'],
                    'starttime' => (int)$row['starttime'],
                    'endtime' => (int)$row['endtime'],
                    'active' => !(bool)$row['hidden']
                        && ((int)$row['starttime'] === 0 || (int)$row['starttime'] <= $now)
                        && ((int)$row['endtime'] === 0 || (int)$row['endtime'] >= $now),
                    'tstamp' => (int)$row['tstamp'],
                    'sourceConversation' => (string)$row['source_conversation'],
                    'sourceConversationShort' => $row['source_conversation'] !== ''
                        ? substr((string)$row['source_conversation'], 0, 8)
                        : '',
                    'editUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $editParams),
                    'toggleUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $toggleParams),
                    'deleteUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $deleteParams),
                ];
            }

            $total = count($rows);
            $limitReached = $total >= 500;
        }

        $sitesForView = [];
        foreach ($offeredSites as $siteObject) {
            $configuration = $siteObject->getConfiguration();
            $sitesForView[] = [
                'identifier' => $siteObject->getIdentifier(),
                'title' => ($configuration['websiteTitle'] ?? '') ?: $siteObject->getIdentifier(),
            ];
        }

        $languages = [];
        foreach ($site->getLanguages() as $siteLanguage) {
            $languages[] = [
                'id' => $siteLanguage->getLanguageId(),
                'title' => $siteLanguage->getTitle(),
            ];
        }

        $newParams = [
            'action' => 'new',
            'site' => $siteIdentifier,
        ];
        if ($language !== '') {
            $newParams['language'] = (int)$language;
        }

        $moduleTemplate->assignMultiple([
            'noSites' => false,
            'sites' => $sitesForView,
            'siteIdentifier' => $siteIdentifier,
            'languages' => $languages,
            'filterLanguage' => $language,
            'q' => $q,
            'state' => $state,
            'rows' => $rows,
            'total' => $total,
            'limitReached' => $limitReached,
            'canModify' => $canModify,
            'newUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $newParams),
            'listUrl' => $this->listUrl($siteIdentifier, $language, $q, $state),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/modal.js');

        return $moduleTemplate->renderResponse('Backend/Knowledge/Index');
    }

    private function newAction(array $params): ResponseInterface
    {
        if (!$this->hasTableModifyPermission()) {
            $this->addFlash(
                'Sie haben keine Berechtigung, Wissenseinträge zu erstellen.',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->listUrl());
        }

        $site = $this->selectedSiteFromParams($params);
        if ($site === null) {
            $this->addFlash('Keine Site mit Zugriff gefunden.', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse($this->listUrl());
        }

        $language = (int)($params['language'] ?? 0);
        if ($language > 0) {
            try {
                $site->getLanguageById($language);
            } catch (\Throwable) {
                $language = 0;
            }
        }

        $title = trim((string)($params['title'] ?? ''));
        $title = (string)mb_substr($title, 0, 255);

        $conversation = strtolower(trim((string)($params['conversation'] ?? '')));
        if (preg_match('/^[0-9a-f]{32}$/', $conversation) !== 1) {
            $conversation = '';
        }

        $defVals = [self::TABLE => array_filter(
            [
                'sys_language_uid' => $language,
                'title' => $title,
            ],
            static fn($value) => $value !== null && $value !== ''
        )];
        // The conversation id goes in as overrideVals, not defVals: the field
        // is read-only in the form, and FormEngine does not submit read-only
        // fields — a default would be shown and then lost on save.
        // overrideVals are rendered as hidden fields and do reach DataHandler.
        $overrideVals = $conversation !== '' ? [self::TABLE => ['source_conversation' => $conversation]] : [];

        $returnUrl = (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, [
            'site' => $site->getIdentifier(),
        ]);

        $editUri = $this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::TABLE => [$site->getRootPageId() => 'new']],
            'defVals' => $defVals,
            'overrideVals' => $overrideVals,
            'returnUrl' => $returnUrl,
        ]);

        return new RedirectResponse((string)$editUri);
    }

    private function editAction(array $params): ResponseInterface
    {
        if (!$this->hasTableModifyPermission()) {
            $this->addFlash(
                'Sie haben keine Berechtigung, Wissenseinträge zu bearbeiten.',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->listUrl());
        }

        $uid = (int)($params['uid'] ?? 0);
        $row = $this->findRowByUid($uid);
        $site = $row !== null ? $this->siteForRow((int)$row['pid']) : null;

        if ($row === null || $site === null) {
            $this->addFlash(
                'Eintrag nicht gefunden oder keine Berechtigung.',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->listUrl());
        }

        $returnUrl = $this->sanitizeReturnUrl($params['returnUrl'] ?? '', $site->getIdentifier());

        $editUri = $this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::TABLE => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);

        return new RedirectResponse((string)$editUri);
    }

    private function toggleAction(array $params): ResponseInterface
    {
        if (!$this->hasTableModifyPermission()) {
            $this->addFlash(
                'Sie haben keine Berechtigung, Wissenseinträge zu bearbeiten.',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->listUrl());
        }

        $uid = (int)($params['uid'] ?? 0);
        $row = $this->findRowByUid($uid);
        $site = $row !== null ? $this->siteForRow((int)$row['pid']) : null;

        if ($row === null || $site === null) {
            $this->addFlash(
                'Eintrag nicht gefunden oder keine Berechtigung.',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->listUrl());
        }

        $newHidden = (int)$row['hidden'] ? 0 : 1;

        // GET links are acceptable for toggle and delete: module route URLs
        // carry TYPO3's CSRF token. DataHandler (not SQL) so permissions and
        // history apply and the indexing hook updates the search index.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            [self::TABLE => [$uid => ['hidden' => $newHidden]]],
            []
        );
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            $this->addFlash(
                'Fehler beim Speichern: ' . implode(' ', $dataHandler->errorLog),
                ContextualFeedbackSeverity::ERROR
            );
        } else {
            $this->addFlash(
                $newHidden ? 'Eintrag ausgeblendet.' : 'Eintrag eingeblendet.',
                ContextualFeedbackSeverity::OK
            );
        }

        $returnUrl = $this->sanitizeReturnUrl($params['returnUrl'] ?? '', $site->getIdentifier());

        return new RedirectResponse($returnUrl);
    }

    private function deleteAction(array $params): ResponseInterface
    {
        if (!$this->hasTableModifyPermission()) {
            $this->addFlash(
                'Sie haben keine Berechtigung, Wissenseinträge zu löschen.',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->listUrl());
        }

        $uid = (int)($params['uid'] ?? 0);
        $row = $this->findRowByUid($uid);
        $site = $row !== null ? $this->siteForRow((int)$row['pid']) : null;

        if ($row === null || $site === null) {
            $this->addFlash(
                'Eintrag nicht gefunden oder keine Berechtigung.',
                ContextualFeedbackSeverity::ERROR
            );

            return new RedirectResponse($this->listUrl());
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            [],
            [self::TABLE => [$uid => ['delete' => 1]]]
        );
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            $this->addFlash(
                'Fehler beim Löschen: ' . implode(' ', $dataHandler->errorLog),
                ContextualFeedbackSeverity::ERROR
            );
        } else {
            $this->addFlash('Eintrag gelöscht.', ContextualFeedbackSeverity::OK);
        }

        $returnUrl = $this->sanitizeReturnUrl($params['returnUrl'] ?? '', $site->getIdentifier());

        return new RedirectResponse($returnUrl);
    }

    /**
     * @return array<string, Site>
     */
    private function offeredSites(): array
    {
        $beUser = $this->getBackendUser();
        if ($beUser === null) {
            return [];
        }

        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($beUser->isAdmin() || $beUser->isInWebMount($site->getRootPageId()) !== null) {
                $sites[$site->getIdentifier()] = $site;
            }
        }

        return $sites;
    }

    private function selectedSiteFromParams(array $params): ?Site
    {
        $sites = $this->offeredSites();
        if ($sites === []) {
            return null;
        }

        $siteIdentifier = (string)($params['site'] ?? '');
        if ($siteIdentifier !== '' && isset($sites[$siteIdentifier])) {
            return $sites[$siteIdentifier];
        }

        return reset($sites);
    }

    private function siteForRow(int $pid): ?Site
    {
        foreach ($this->offeredSites() as $site) {
            if ($site->getRootPageId() === $pid) {
                return $site;
            }
        }

        return null;
    }

    private function findRowByUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('uid', 'pid', 'hidden')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
            ))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    private function listUrl(
        string $siteIdentifier = '',
        string $language = '',
        string $q = '',
        string $state = ''
    ): string {
        $params = [];
        if ($siteIdentifier !== '') {
            $params['site'] = $siteIdentifier;
        }
        if ($language !== '') {
            $params['language'] = (int)$language;
        }
        if ($q !== '') {
            $params['q'] = $q;
        }
        if ($state !== '') {
            $params['state'] = $state;
        }

        return (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $params);
    }

    /**
     * Only local URLs: a check for a leading "/" alone would let
     * "//other-host.example" through as an open redirect.
     */
    private function sanitizeReturnUrl(string $returnUrl, string $siteIdentifier): string
    {
        $returnUrl = GeneralUtility::sanitizeLocalUrl($returnUrl);

        return $returnUrl !== '' ? $returnUrl : $this->listUrl($siteIdentifier);
    }

    private function hasTableSelectPermission(): bool
    {
        return $this->getBackendUser()?->check('tables_select', self::TABLE) === true;
    }

    private function hasTableModifyPermission(): bool
    {
        return $this->getBackendUser()?->check('tables_modify', self::TABLE) === true;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;

        return $beUser instanceof BackendUserAuthentication ? $beUser : null;
    }

    private function addFlash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $queue = $this->flashMessageService->getMessageQueueByIdentifier();
        $queue->addMessage(new FlashMessage($message, '', $severity, true));
    }
}

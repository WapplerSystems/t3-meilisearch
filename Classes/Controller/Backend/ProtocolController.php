<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Service\Rag\CitationRenderer;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolRepository;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ChatProtocolTranscript;
use WapplerSystems\Meilisearch\Service\Rag\Protocol\ProtocolFilter;

/**
 * Backend "Chat-Protokoll" tab — quality-control and support lookup for the RAG chatbot.
 *
 * Editors can browse stored conversations, filter for escalations/problems,
 * open a conversation and download its plain-text transcript. A visitor
 * quoting the 32-char conversation id in a contact-form mail can be looked
 * up directly via the prominent id field.
 *
 * This controller is intentionally read-only: protocol rows are removed
 * exclusively by the retention command ws_meilisearch:rag:protocol:prune,
 * not through the backend UI.
 */
final class ProtocolController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ChatProtocolRepository $repository,
        private readonly ChatProtocolTranscript $transcript,
        private readonly SiteFinder $siteFinder,
        private readonly BackendContext $context,
    ) {}

    public function handle(ServerRequestInterface $request, string $action): ResponseInterface
    {
        if ($action === 'protocolDownload') {
            return $this->downloadTranscript($request);
        }

        return $this->listOrDetail($request);
    }

    private function listOrDetail(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $params = $request->getQueryParams();

        $availableSites = [];
        $protocolEnabledSites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $availableSites[] = $site->getIdentifier();
            if ((bool)$site->getSettings()->get('meilisearch.rag.protocol.enabled', false) === true) {
                $protocolEnabledSites[] = $site->getIdentifier();
            }
        }

        $siteFilter = (string)($params['site'] ?? '');
        if (!in_array($siteFilter, $availableSites, true)) {
            $siteFilter = '';
        }

        $days = in_array((int)($params['days'] ?? 30), [0, 1, 7, 30, 90], true)
            ? (int)($params['days'] ?? 30)
            : 30;
        $onlyEscalated = ($params['escalated'] ?? '') === '1';
        $onlyProblems = ($params['problems'] ?? '') === '1';
        $search = trim((string)($params['q'] ?? ''));
        $search = mb_substr($search, 0, 200);
        $conversationParam = strtolower(trim((string)($params['conversation'] ?? '')));
        $page = max(1, (int)($params['page'] ?? 1));

        $conversationEntries = [];
        $isValidConversationId = preg_match('/^[0-9a-f]{32}$/', $conversationParam) === 1;
        if ($isValidConversationId) {
            $conversationEntries = $this->repository->findByConversation(
                $conversationParam,
                $siteFilter !== '' ? $siteFilter : null
            );
        }

        if ($conversationEntries !== []) {
            $entryRows = $this->buildDetailEntries($conversationEntries);
            $detailSiteIdentifier = $siteFilter !== '' ? $siteFilter : $conversationEntries[0]->siteIdentifier;

            $moduleTemplate->assignMultiple([
                'entries' => $entryRows,
                'conversationId' => $conversationParam,
                'siteIdentifier' => $detailSiteIdentifier,
                'downloadUrl' => $this->context->route('protocolDownload', ['conversation' => $conversationParam]),
                'backUrl' => $this->listUrl($page, $siteFilter, $days, $onlyEscalated, $onlyProblems, $search),
                ...$this->context->tabNavData(),
                'protocolUrl' => $this->context->route('protocol'),
                'active' => 'protocol',
                'baseUrl' => $this->context->route('protocol'),
            ]);

            return $moduleTemplate->renderResponse('Backend/Overview/Protocol');
        }

        if ($conversationParam !== '') {
            $this->context->addFlash(
                'Kein Protokoll zu dieser ID gefunden.',
                ContextualFeedbackSeverity::WARNING
            );
        }

        $filter = new ProtocolFilter(
            siteIdentifier: $siteFilter !== '' ? $siteFilter : null,
            onlyEscalated: $onlyEscalated,
            onlyProblems: $onlyProblems,
            search: $search,
            since: $days > 0 ? time() - ($days * 86400) : 0,
        );

        $total = $this->repository->countConversations($filter);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = [];
        foreach ($this->repository->findConversations($filter, self::PER_PAGE, $offset) as $row) {
            $row['detailUrl'] = $this->listUrl(
                $page,
                $siteFilter,
                $days,
                $onlyEscalated,
                $onlyProblems,
                $search,
                ['conversation' => $row['conversationId']]
            );
            $rows[] = $row;
        }

        $prevUrl = $page > 1
            ? $this->listUrl($page - 1, $siteFilter, $days, $onlyEscalated, $onlyProblems, $search)
            : '';
        $nextUrl = $page < $pages
            ? $this->listUrl($page + 1, $siteFilter, $days, $onlyEscalated, $onlyProblems, $search)
            : '';

        $moduleTemplate->assignMultiple([
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'prevUrl' => $prevUrl,
            'nextUrl' => $nextUrl,
            'siteFilter' => $siteFilter,
            'days' => $days,
            'onlyEscalated' => $onlyEscalated,
            'onlyProblems' => $onlyProblems,
            'search' => $search,
            'conversationParam' => $conversationParam,
            'availableSites' => $availableSites,
            'protocolEnabledSites' => $protocolEnabledSites,
            ...$this->context->tabNavData(),
            'protocolUrl' => $this->context->route('protocol'),
            'active' => 'protocol',
            'baseUrl' => $this->context->route('protocol'),
        ]);

        return $moduleTemplate->renderResponse('Backend/Overview/Protocol');
    }

    /**
     * @param list<\WapplerSystems\Meilisearch\Service\Rag\Protocol\ProtocolEntry> $protocolEntries
     * @return list<array<string, mixed>>
     */
    private function buildDetailEntries(array $protocolEntries): array
    {
        $sitesByIdentifier = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $sitesByIdentifier[$site->getIdentifier()] = $site;
        }

        $entries = [];
        foreach ($protocolEntries as $entry) {
            $languageTitle = '#' . $entry->languageId;
            if (isset($sitesByIdentifier[$entry->siteIdentifier])) {
                try {
                    $languageTitle = $sitesByIdentifier[$entry->siteIdentifier]
                        ->getLanguageById($entry->languageId)
                        ->getTitle();
                } catch (\Throwable) {
                    // Keep the fallback when the site or language vanished.
                }
            }

            $sources = [];
            foreach ($entry->sources as $source) {
                $sourceId = (string)($source['id'] ?? '');
                $sources[] = [
                    'id' => $sourceId,
                    'type' => (string)($source['type'] ?? ''),
                    'title' => (string)($source['title'] ?? ''),
                    'uri' => (string)($source['uri'] ?? ''),
                    'cited' => $entry->wasCited($sourceId),
                ];
            }

            $entries[] = [
                'uid' => $entry->uid,
                'crdate' => $entry->crdate,
                'languageId' => $entry->languageId,
                'languageTitle' => $languageTitle,
                'question' => $entry->question,
                'answerHtml' => CitationRenderer::render($entry->answer, $entry->sources),
                'status' => $entry->status,
                'escalated' => $entry->escalated,
                'isProblem' => in_array($entry->status, ProtocolFilter::PROBLEM_STATUSES, true)
                    || ($entry->status === 'ok' && $entry->citedIds === []),
                'sources' => $sources,
            ];
        }

        return $entries;
    }

    private function downloadTranscript(ServerRequestInterface $request): ResponseInterface
    {
        $id = strtolower(trim((string)($request->getQueryParams()['conversation'] ?? '')));
        $entries = [];
        if (preg_match('/^[0-9a-f]{32}$/', $id) === 1) {
            $entries = $this->repository->findByConversation($id);
        }

        if ($entries === []) {
            $this->context->addFlash(
                'Kein Protokoll zu dieser ID gefunden.',
                ContextualFeedbackSeverity::WARNING
            );

            return $this->context->redirect('protocol');
        }

        $labels = [
            'title' => 'Chat-Protokoll',
            'conversation' => 'Gespräch',
            'question' => 'Frage',
            'answer' => 'Antwort',
            'status' => 'Status',
            'sources' => 'Quellen',
            'cited' => 'zitiert',
            'escalated' => 'Kontaktangebot angezeigt',
            'noAnswer' => '(keine Antwort)',
        ];

        $transcript = $this->transcript->toText($entries, $labels);

        // Response's first argument is a stream identifier, not the body —
        // a string there would be opened as a file path.
        $response = new Response('php://temp', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="chat-protocol-' . $id . '.txt"',
        ]);
        $response->getBody()->write($transcript);

        return $response;
    }

    /**
     * Builds a list URL while keeping the current filter values.
     *
     * @param array<string, string> $additional
     */
    private function listUrl(
        int $page,
        string $siteFilter,
        int $days,
        bool $onlyEscalated,
        bool $onlyProblems,
        string $search,
        array $additional = []
    ): string {
        $extra = [
            'page' => $page,
            'days' => $days,
        ];

        if ($siteFilter !== '') {
            $extra['site'] = $siteFilter;
        }
        if ($onlyEscalated) {
            $extra['escalated'] = '1';
        }
        if ($onlyProblems) {
            $extra['problems'] = '1';
        }
        if ($search !== '') {
            $extra['q'] = $search;
        }

        return $this->context->route('protocol', array_merge($extra, $additional));
    }
}

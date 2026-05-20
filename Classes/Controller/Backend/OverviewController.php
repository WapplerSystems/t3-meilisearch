<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Service\EmbedderConfigurator;
use WapplerSystems\Meilisearch\Service\IndexerService;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderRegistry;
use WapplerSystems\Meilisearch\Service\Rag\RagService;
use WapplerSystems\Meilisearch\Service\SearchEngineFactory;
use WapplerSystems\Meilisearch\Service\SearchService;

/**
 * Backend module for ws_meilisearch. One controller, one route, sub-action
 * dispatched on the `?action=` query param — same pattern as the other
 * WapplerSystems backend modules so editors don't see surprising URL shapes
 * between modules.
 *
 *   ?action=index   (default) overview of all sites + their index state
 *   ?action=reindex POST  trigger reindex for a single site
 *   ?action=test    GET   ad-hoc search + RAG forms against a chosen site
 */
#[AsController]
final class OverviewController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly SearchEngineFactory $engineFactory,
        private readonly IndexerService $indexerService,
        private readonly SearchService $searchService,
        private readonly RagService $ragService,
        private readonly LlmProviderRegistry $providerRegistry,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $action = (string)($request->getQueryParams()['action'] ?? 'index');
        return match ($action) {
            'reindex' => $this->reindexAction($request),
            'test'    => $this->testAction($request),
            default   => $this->indexAction($request),
        };
    }

    private function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $rows = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $rows[] = $this->buildSiteRow($site);
        }

        $moduleTemplate->assignMultiple([
            'sites' => $rows,
            'reindexUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'reindex']),
            'testUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'test']),
            'availableProviders' => $this->providerRegistry->names(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Index');
    }

    private function reindexAction(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->redirectToIndex();
        }
        $parsed = (array)$request->getParsedBody();
        $siteId = (string)($parsed['site'] ?? '');
        $rebuild = !empty($parsed['rebuild']);
        if ($siteId === '') {
            return $this->redirectToIndex();
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteId);
        } catch (\Throwable) {
            $this->addFlash('Unknown site: ' . $siteId, ContextualFeedbackSeverity::ERROR);
            return $this->redirectToIndex();
        }

        try {
            if (!$this->indexerService->ensureSchema($site, $rebuild)) {
                $this->addFlash(sprintf('Site "%s" is not configured for Meilisearch.', $siteId), ContextualFeedbackSeverity::WARNING);
                return $this->redirectToIndex();
            }
            $count = $this->indexerService->indexAll($site);
            $this->addFlash(sprintf('Reindexed %d document(s) for site "%s".', $count, $siteId), ContextualFeedbackSeverity::OK);
        } catch (\Throwable $e) {
            $this->addFlash('Reindex failed: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return $this->redirectToIndex();
    }

    private function testAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $query = (string)($request->getQueryParams()['q'] ?? '');
        $askQuery = (string)($request->getQueryParams()['ask'] ?? '');
        $siteId = (string)($request->getQueryParams()['site'] ?? '');
        $hybrid = (bool)($request->getQueryParams()['hybrid'] ?? false);

        $sites = $this->siteFinder->getAllSites();
        $site = null;
        if ($siteId !== '') {
            try {
                $site = $this->siteFinder->getSiteByIdentifier($siteId);
            } catch (\Throwable) {
                $site = null;
            }
        }
        if ($site === null && $sites !== []) {
            $site = $sites[array_key_first($sites)];
            $siteId = $site->getIdentifier();
        }

        $searchResult = null;
        if ($site instanceof Site && $query !== '') {
            $searchResult = $this->searchService->search($site, $query, [
                'hybrid' => $hybrid,
                'perPage' => 10,
                'facets' => ['type', 'language'],
            ]);
        }

        $ragAnswer = null;
        $ragSources = [];
        if ($site instanceof Site && $askQuery !== '') {
            $ragAnswer = $this->ragService->ask($site, $askQuery);
            // Pre-flag sources as cited so the template doesn't need a
            // nested loop just to render the ✓ marker.
            $citedSet = array_flip($ragAnswer->citedIds);
            foreach ($ragAnswer->sources as $src) {
                $id = (string)($src['id'] ?? '');
                $src['cited'] = isset($citedSet[$id]);
                $ragSources[] = $src;
            }
        }

        $moduleTemplate->assignMultiple([
            'siteOptions' => array_map(static fn (Site $s) => $s->getIdentifier(), array_values($sites)),
            'selectedSite' => $siteId,
            'query' => $query,
            'askQuery' => $askQuery,
            'hybrid' => $hybrid,
            'searchResult' => $searchResult,
            'ragAnswer' => $ragAnswer,
            'ragSources' => $ragSources,
            'indexUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch'),
            'testUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'test']),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Test');
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSiteRow(Site $site): array
    {
        $settings = $site->getSettings();
        $configured = trim((string)$settings->get('meilisearch.url', '')) !== '';

        $row = [
            'identifier' => $site->getIdentifier(),
            'configured' => $configured,
            'indexName' => $configured ? $this->engineFactory->getIndexName($site) : '',
            'embedderSource' => (string)$settings->get('meilisearch.embedder.source', ''),
            'ragProvider' => (string)$settings->get('meilisearch.rag.provider', ''),
            'docCount' => null,
            'embedderActive' => false,
            'error' => null,
        ];

        if (!$configured) {
            return $row;
        }

        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return $row;
        }
        $index = $client->index($row['indexName']);

        try {
            $stats = $index->stats();
            $row['docCount'] = (int)($stats['numberOfDocuments'] ?? 0);
        } catch (\Throwable $e) {
            $row['error'] = $e->getMessage();
        }
        try {
            $embedders = $index->getEmbedders();
            $row['embedderActive'] = is_array($embedders) && isset($embedders[EmbedderConfigurator::EMBEDDER_NAME]);
        } catch (\Throwable) {
            $row['embedderActive'] = false;
        }
        return $row;
    }

    private function redirectToIndex(): ResponseInterface
    {
        return new RedirectResponse(
            (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch'),
        );
    }

    private function addFlash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $queue = $this->flashMessageService->getMessageQueueByIdentifier();
        $queue->addMessage(new FlashMessage($message, '', $severity, true));
    }
}

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
use WapplerSystems\Meilisearch\Service\Llm\LlmException;
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
        private readonly EmbedderConfigurator $embedderConfigurator,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $action = (string)($request->getQueryParams()['action'] ?? 'index');
        return match ($action) {
            'reindex'         => $this->reindexAction($request),
            'test'            => $this->testAction($request),
            'diagnose'        => $this->diagnoseAction($request),
            'repushEmbedder'  => $this->repushEmbedderAction($request),
            'pingRag'         => $this->pingRagAction($request),
            default           => $this->indexAction($request),
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
            'availableProviders' => $this->providerRegistry->names(),
            ...$this->commonTabUrls(),
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
        $sort = trim((string)($request->getQueryParams()['sort'] ?? ''));
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));

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
                'page' => $page,
                'perPage' => 10,
                'facets' => ['type', 'language'],
                'sort' => $sort,
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
            'sort' => $sort,
            'page' => $page,
            'searchResult' => $searchResult,
            'ragAnswer' => $ragAnswer,
            'ragSources' => $ragSources,
            ...$this->commonTabUrls(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Test');
    }

    private function diagnoseAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $cards = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $cards[] = $this->buildDiagnosticsCard($site);
        }

        $moduleTemplate->assignMultiple([
            'cards' => $cards,
            'repushUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'repushEmbedder']),
            'pingUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'pingRag']),
            ...$this->commonTabUrls(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Diagnose');
    }

    /**
     * Shared tab-nav URLs. The TabNav partial wants indexUrl / testUrl /
     * diagnoseUrl on every action; pulling them out keeps each action
     * method's assignMultiple readable.
     *
     * @return array<string,string>
     */
    private function commonTabUrls(): array
    {
        return [
            'indexUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch'),
            'testUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'test']),
            'diagnoseUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'diagnose']),
        ];
    }

    private function repushEmbedderAction(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->redirectToDiagnose();
        }
        $siteId = (string)(($request->getParsedBody() ?? [])['site'] ?? '');
        if ($siteId === '') {
            return $this->redirectToDiagnose();
        }
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteId);
        } catch (\Throwable) {
            $this->addFlash('Unknown site: ' . $siteId, ContextualFeedbackSeverity::ERROR);
            return $this->redirectToDiagnose();
        }
        try {
            $result = $this->embedderConfigurator->ensureForSite($site);
            // ensureForSite returns: 'configured' | 'unchanged' | 'disabled' | 'skipped'
            $severity = match ($result) {
                'configured' => ContextualFeedbackSeverity::OK,
                'unchanged'  => ContextualFeedbackSeverity::INFO,
                'disabled'   => ContextualFeedbackSeverity::INFO,
                'skipped'    => ContextualFeedbackSeverity::WARNING,
                default      => ContextualFeedbackSeverity::INFO,
            };
            $this->addFlash(sprintf('Embedder push for "%s": %s', $siteId, $result), $severity);
        } catch (\Throwable $e) {
            $this->addFlash('Embedder push failed: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return $this->redirectToDiagnose();
    }

    private function pingRagAction(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->redirectToDiagnose();
        }
        $siteId = (string)(($request->getParsedBody() ?? [])['site'] ?? '');
        if ($siteId === '') {
            return $this->redirectToDiagnose();
        }
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteId);
        } catch (\Throwable) {
            $this->addFlash('Unknown site: ' . $siteId, ContextualFeedbackSeverity::ERROR);
            return $this->redirectToDiagnose();
        }

        $settings = $site->getSettings();
        $providerName = trim((string)$settings->get('meilisearch.rag.provider', ''));
        if ($providerName === '') {
            $this->addFlash(sprintf('Site "%s" has no RAG provider configured.', $siteId), ContextualFeedbackSeverity::WARNING);
            return $this->redirectToDiagnose();
        }
        $provider = $this->providerRegistry->get($providerName);
        if ($provider === null) {
            $this->addFlash(sprintf('Provider "%s" not registered.', $providerName), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToDiagnose();
        }

        // Single short ping prompt. We deliberately don't go through
        // RagService because retrieval isn't part of the health check —
        // we only care whether the LLM endpoint responds.
        $start = microtime(true);
        try {
            $answer = $provider->complete(
                [
                    ['role' => 'system', 'content' => 'You are a health probe. Reply "pong" to any input.'],
                    ['role' => 'user', 'content' => 'ping'],
                ],
                [
                    'model' => (string)$settings->get('meilisearch.rag.model', ''),
                    'apiKey' => (string)$settings->get('meilisearch.rag.apiKey', ''),
                    'url' => (string)$settings->get('meilisearch.rag.url', ''),
                    'temperature' => 0.0,
                    'maxTokens' => 16,
                ],
            );
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);
            $excerpt = trim(mb_substr($answer, 0, 80));
            $this->addFlash(
                sprintf('RAG ping for "%s" succeeded in %d ms — reply: "%s"', $siteId, $elapsedMs, $excerpt),
                ContextualFeedbackSeverity::OK,
            );
        } catch (LlmException $e) {
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);
            $this->addFlash(
                sprintf('RAG ping for "%s" failed after %d ms: %s', $siteId, $elapsedMs, $e->getMessage()),
                ContextualFeedbackSeverity::ERROR,
            );
        }
        return $this->redirectToDiagnose();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDiagnosticsCard(Site $site): array
    {
        $settings = $site->getSettings();
        $configured = trim((string)$settings->get('meilisearch.url', '')) !== '';

        $card = [
            'identifier' => $site->getIdentifier(),
            'configured' => $configured,
            // Desired embedder config (what the operator put in settings.yaml)
            'desiredEmbedder' => $this->describeDesiredEmbedder($site),
            'actualEmbedder' => null,
            // RAG block
            'rag' => $this->describeRagConfig($site),
            'error' => null,
        ];
        if (!$configured) {
            return $card;
        }

        $client = $this->engineFactory->createClientForSite($site);
        if ($client === null) {
            return $card;
        }
        try {
            $index = $client->index($this->engineFactory->getIndexName($site));
            $actual = $index->getEmbedders();
            if (is_array($actual) && isset($actual[EmbedderConfigurator::EMBEDDER_NAME])) {
                $card['actualEmbedder'] = $actual[EmbedderConfigurator::EMBEDDER_NAME];
            }
        } catch (\Throwable $e) {
            $card['error'] = $e->getMessage();
        }
        return $card;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function describeDesiredEmbedder(Site $site): ?array
    {
        $settings = $site->getSettings();
        $source = trim((string)$settings->get('meilisearch.embedder.source', ''));
        if ($source === '') {
            return null;
        }
        // apiKey deliberately omitted — we never want to render it.
        return array_filter([
            'source' => $source,
            'model' => trim((string)$settings->get('meilisearch.embedder.model', '')),
            'url' => trim((string)$settings->get('meilisearch.embedder.url', '')),
            'dimensions' => (int)$settings->get('meilisearch.embedder.dimensions', 0) ?: null,
            'documentTemplate' => trim((string)$settings->get('meilisearch.embedder.documentTemplate', '')),
            'semanticRatio' => (float)$settings->get('meilisearch.embedder.semanticRatio', 0.5),
        ], static fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function describeRagConfig(Site $site): ?array
    {
        $settings = $site->getSettings();
        $provider = trim((string)$settings->get('meilisearch.rag.provider', ''));
        if ($provider === '') {
            return null;
        }
        return [
            'provider' => $provider,
            'model' => trim((string)$settings->get('meilisearch.rag.model', '')),
            'url' => trim((string)$settings->get('meilisearch.rag.url', '')),
            'hasApiKey' => trim((string)$settings->get('meilisearch.rag.apiKey', '')) !== '',
            'useHybrid' => (bool)$settings->get('meilisearch.rag.useHybrid', true),
            'conversationEnabled' => (bool)$settings->get('meilisearch.rag.conversation.enabled', false),
            'maxContextHits' => (int)$settings->get('meilisearch.rag.maxContextHits', 5),
            'temperature' => (float)$settings->get('meilisearch.rag.temperature', 0.2),
        ];
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

    private function redirectToDiagnose(): ResponseInterface
    {
        return new RedirectResponse(
            (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'diagnose']),
        );
    }

    private function addFlash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $queue = $this->flashMessageService->getMessageQueueByIdentifier();
        $queue->addMessage(new FlashMessage($message, '', $severity, true));
    }
}

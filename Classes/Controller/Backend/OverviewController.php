<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Service\IndexerService;
use WapplerSystems\Meilisearch\Service\IndexMetadataProvider;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderRegistry;

/**
 * Backend module entry-point for ws_meilisearch. Owns two concerns:
 *
 *  1. The Overview tab (per-site index status + Reindex / Rebuild
 *     buttons) — small enough to live here.
 *  2. Top-level dispatch: `?action=` selects which sub-controller
 *     gets the request. Test, Diagnose and KnowledgeResource each own their
 *     dependency surface and helpers — kept out of this class so the
 *     entry-point stays a thin router.
 *
 *   ?action=index   (default)        overview of all sites
 *   ?action=reindex POST             trigger reindex for one site
 *   ?action=test    GET              → TestController
 *   ?action=diagnose|repushEmbedder|pingRag → DiagnoseController
 *   ?action=knowledgeResources|runImporter|purgeKnowledgeResources → KnowledgeResourceController
 */
#[AsController]
final class OverviewController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly IndexerService $indexerService,
        private readonly IndexMetadataProvider $metadataProvider,
        private readonly LlmProviderRegistry $providerRegistry,
        private readonly BackendContext $context,
        private readonly TestController $testController,
        private readonly DiagnoseController $diagnoseController,
        private readonly KnowledgeResourceController $helpDocController,
        private readonly RagTestController $ragTestController,
        private readonly AnalyticsController $analyticsController,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $action = (string)($request->getQueryParams()['action'] ?? 'index');
        return match ($action) {
            'reindex' => $this->reindexAction($request),
            'test' => $this->testController->handle($request),
            'diagnose', 'repushEmbedder', 'pingRag' =>
                $this->diagnoseController->handle($request, $action),
            'knowledgeResources', 'runImporter', 'purgeKnowledgeResources' =>
                $this->helpDocController->handle($request, $action),
            'ragtests', 'runRagTest', 'runAllRagTests', 'adoptActualAsExpected' =>
                $this->ragTestController->handle($request, $action),
            'analytics' => $this->analyticsController->handle($request),
            default => $this->indexAction($request),
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
            'reindexUrl' => $this->context->route('reindex'),
            'availableProviders' => $this->providerRegistry->names(),
            ...$this->context->tabNavData(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Index');
    }

    private function reindexAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($wrong = $this->context->requirePost($request)) {
            return $wrong;
        }
        $parsed = (array)$request->getParsedBody();
        $siteId = (string)($parsed['site'] ?? '');
        $rebuild = !empty($parsed['rebuild']);
        if ($siteId === '') {
            return $this->context->redirect();
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteId);
        } catch (\Throwable) {
            $this->context->addFlash('Unknown site: ' . $siteId, ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect();
        }

        try {
            if (!$this->indexerService->ensureSchema($site, $rebuild)) {
                $this->context->addFlash(sprintf('Site "%s" is not configured for Meilisearch.', $siteId), ContextualFeedbackSeverity::WARNING);
                return $this->context->redirect();
            }
            $count = $this->indexerService->indexAll($site);
            $this->metadataProvider->invalidate($site);
            $this->context->addFlash(sprintf('Reindexed %d document(s) for site "%s".', $count, $siteId), ContextualFeedbackSeverity::OK);
        } catch (\Throwable $e) {
            $this->context->addFlash('Reindex failed: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return $this->context->redirect();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSiteRow(Site $site): array
    {
        $settings = $site->getSettings();
        $meta = $this->metadataProvider->getMeta($site);
        return [
            'identifier' => $site->getIdentifier(),
            'configured' => $meta['configured'],
            'indexName' => $meta['indexName'],
            'embedderSource' => (string)$settings->get('meilisearch.embedder.source', ''),
            'ragProvider' => (string)$settings->get('meilisearch.rag.provider', ''),
            'docCount' => $meta['docCount'],
            'embedderActive' => $meta['embedderActive'],
            'error' => $meta['error'],
        ];
    }
}

<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Controller\Backend\Support\SiteOverviewProvider;
use WapplerSystems\Meilisearch\Service\EmbedderConfigurator;
use WapplerSystems\Meilisearch\Service\IndexMetadataProvider;
use WapplerSystems\Meilisearch\Service\Llm\LlmException;
use WapplerSystems\Meilisearch\Service\Llm\LlmProviderRegistry;

/**
 * The Diagnostics tab + its two maintenance actions:
 *
 *   - diagnose      GET   per-site card with desired-vs-actual
 *                          embedder config and RAG provider state
 *   - repushEmbedder POST one-shot EmbedderConfigurator::ensureForSite()
 *   - pingRag       POST  one-shot ping→pong against the configured
 *                          LLM provider, bypassing retrieval entirely
 *
 * Split out of OverviewController so it carries only the services it
 * actually needs (EmbedderConfigurator + LlmProviderRegistry + the
 * Meilisearch client factory for embedder read-back).
 */
final class DiagnoseController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly IndexMetadataProvider $metadataProvider,
        private readonly SiteOverviewProvider $siteOverviewProvider,
        private readonly LlmProviderRegistry $providerRegistry,
        private readonly EmbedderConfigurator $embedderConfigurator,
        private readonly BackendContext $context,
    ) {}

    public function handle(ServerRequestInterface $request, string $action): ResponseInterface
    {
        return match ($action) {
            'repushEmbedder' => $this->repushEmbedder($request),
            'pingRag'        => $this->pingRag($request),
            default          => $this->diagnose($request),
        };
    }

    private function diagnose(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $cards = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $cards[] = $this->buildDiagnosticsCard($site);
        }

        $moduleTemplate->assignMultiple([
            'cards' => $cards,
            'repushUrl' => $this->context->route('repushEmbedder'),
            'pingUrl' => $this->context->route('pingRag'),
            ...$this->context->tabNavData(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Diagnose');
    }

    private function repushEmbedder(ServerRequestInterface $request): ResponseInterface
    {
        if ($wrong = $this->context->requirePost($request, 'diagnose')) {
            return $wrong;
        }
        $siteId = (string)(($request->getParsedBody() ?? [])['site'] ?? '');
        if ($siteId === '') {
            return $this->context->redirect('diagnose');
        }
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteId);
        } catch (\Throwable) {
            $this->context->addFlash('Unknown site: ' . $siteId, ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('diagnose');
        }
        try {
            $result = $this->embedderConfigurator->ensureForSite($site);
            // Invalidate the metadata cache so the next render of the
            // Diagnostics card shows the just-pushed embedder state
            // instead of the stale "not pushed" badge.
            $this->metadataProvider->invalidate($site);
            // ensureForSite returns: 'configured' | 'unchanged' | 'disabled' | 'skipped'
            $severity = match ($result) {
                'configured' => ContextualFeedbackSeverity::OK,
                'unchanged'  => ContextualFeedbackSeverity::INFO,
                'disabled'   => ContextualFeedbackSeverity::INFO,
                'skipped'    => ContextualFeedbackSeverity::WARNING,
                default      => ContextualFeedbackSeverity::INFO,
            };
            $this->context->addFlash(sprintf('Embedder push for "%s": %s', $siteId, $result), $severity);
        } catch (\Throwable $e) {
            $this->context->addFlash('Embedder push failed: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return $this->context->redirect('diagnose');
    }

    private function pingRag(ServerRequestInterface $request): ResponseInterface
    {
        if ($wrong = $this->context->requirePost($request, 'diagnose')) {
            return $wrong;
        }
        $siteId = (string)(($request->getParsedBody() ?? [])['site'] ?? '');
        if ($siteId === '') {
            return $this->context->redirect('diagnose');
        }
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteId);
        } catch (\Throwable) {
            $this->context->addFlash('Unknown site: ' . $siteId, ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('diagnose');
        }

        $settings = $site->getSettings();
        $providerName = trim((string)$settings->get('meilisearch.rag.provider', ''));
        if ($providerName === '') {
            $this->context->addFlash(sprintf('Site "%s" has no RAG provider configured.', $siteId), ContextualFeedbackSeverity::WARNING);
            return $this->context->redirect('diagnose');
        }
        $provider = $this->providerRegistry->get($providerName);
        if ($provider === null) {
            $this->context->addFlash(sprintf('Provider "%s" not registered.', $providerName), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('diagnose');
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
                    // Vendor-specific tenant id — InfomaniakProvider needs
                    // it to build the base URL when no explicit `url` is
                    // configured. Generic providers ignore it. Mirrors
                    // the option set RagService::ask() passes.
                    'productId' => (string)$settings->get('meilisearch.infomaniak.productId', ''),
                    'temperature' => 0.0,
                    'maxTokens' => 16,
                ],
            );
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);
            $excerpt = trim(mb_substr($answer, 0, 80));
            $this->context->addFlash(
                sprintf('RAG ping for "%s" succeeded in %d ms — reply: "%s"', $siteId, $elapsedMs, $excerpt),
                ContextualFeedbackSeverity::OK,
            );
        } catch (LlmException $e) {
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);
            $this->context->addFlash(
                sprintf('RAG ping for "%s" failed after %d ms: %s', $siteId, $elapsedMs, $e->getMessage()),
                ContextualFeedbackSeverity::ERROR,
            );
        }
        return $this->context->redirect('diagnose');
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDiagnosticsCard(Site $site): array
    {
        $meta = $this->metadataProvider->getMeta($site);
        return [
            'identifier' => $site->getIdentifier(),
            'configured' => $meta['configured'],
            'desiredEmbedder' => $this->siteOverviewProvider->describeDesiredEmbedder($site),
            'actualEmbedder' => $meta['actualEmbedder'],
            'rag' => $this->siteOverviewProvider->describeRagConfig($site),
            'error' => $meta['error'],
        ];
    }
}

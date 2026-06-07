<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Service\EmbedderConfigurator;
use WapplerSystems\Meilisearch\Service\Import\HelpDocRepository;
use WapplerSystems\Meilisearch\Service\Import\HelpDocSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\SourceImporterRegistry;
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
        private readonly SourceImporterRegistry $importerRegistry,
        private readonly HelpDocRepository $helpDocRepository,
        private readonly PageRenderer $pageRenderer,
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
            'helpdocs'        => $this->helpdocsAction($request),
            // Single dispatcher for every registered HelpDocSourceImporter.
            // The form POSTs a hidden `_importer` field; the action looks
            // the slug up in the registry, builds the config from the
            // importer's own describeFields() schema, and runs it. Adding
            // a new importer needs zero controller / template changes.
            'runImporter'     => $this->runImporterAction($request),
            'purgeHelpdocs'   => $this->purgeHelpdocsAction($request),
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
        $rawFilters = $request->getQueryParams()['filter'] ?? [];
        $filters = is_array($rawFilters) ? array_filter(array_map('strval', $rawFilters), static fn ($v) => $v !== '') : [];

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
            // Default to the first site that is actually configured for
            // Meilisearch — landing on a "not configured" site by accident
            // shows nothing and confuses first-time users.
            foreach ($sites as $candidate) {
                if (trim((string)$candidate->getSettings()->get('meilisearch.url', '')) !== '') {
                    $site = $candidate;
                    break;
                }
            }
            $site ??= $sites[array_key_first($sites)];
            $siteId = $site->getIdentifier();
        }

        $searchResult = null;
        $hasSearchInput = $query !== '' || $sort !== '' || $filters !== [];
        if ($site instanceof Site && $hasSearchInput) {
            $searchResult = $this->searchService->search($site, $query, [
                'hybrid' => $hybrid,
                'page' => $page,
                'perPage' => 10,
                'facets' => ['type', 'language'],
                'filters' => $filters,
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
            'filters' => $filters,
            'searchResult' => $searchResult,
            'ragAnswer' => $ragAnswer,
            'ragSources' => $ragSources,
            'examples' => $this->buildExamples($site, $siteId),
            'prevPageUrl' => $searchResult?->getHasPreviousPage()
                ? $this->buildPageUrl($request, $page - 1)
                : null,
            'nextPageUrl' => $searchResult?->getHasNextPage()
                ? $this->buildPageUrl($request, $page + 1)
                : null,
            ...$this->commonTabUrls(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/Test');
    }

    /**
     * Build a paginated link by replacing the `page` parameter in the
     * current request's query string. Used by the Test view's Prev /
     * Next buttons. We can't use f:link.action here because this
     * module isn't Extbase — there's no Request in the ViewHelper
     * scope to resolve from.
     */
    private function buildPageUrl(ServerRequestInterface $request, int $page): string
    {
        $params = $request->getQueryParams();
        $params['page'] = $page;
        // Drop the BE module token; backendUriBuilder regenerates it.
        unset($params['token']);
        return (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', $params);
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
     * Preset queries that demonstrate each major feature. Each one is
     * rendered as a clickable card in the Test page; the link carries
     * the full set of query params required to reproduce the example
     * (site, q, sort, hybrid, filter[*], ask). The presets stay
     * available even when the selected site doesn't support the
     * specific feature — clicking a hybrid example without an
     * embedder configured just shows the regular keyword result,
     * which is still useful as a "what does it look like" preview.
     *
     * @return list<array{label:string,description:string,feature:string,params:array<string,mixed>}>
     */
    private function buildExamples(?Site $site, string $siteId): array
    {
        $hasEmbedder = $site instanceof Site
            && trim((string)$site->getSettings()->get('meilisearch.embedder.source', '')) !== '';
        $hasRag = $site instanceof Site
            && trim((string)$site->getSettings()->get('meilisearch.rag.provider', '')) !== '';

        $base = ['site' => $siteId];
        $examples = [
            [
                'label' => 'Keyword search',
                'description' => 'Plain typo-tolerant full-text search across pages, news, and indexed files.',
                'feature' => 'phase 1',
                'params' => $base + ['q' => 'saskatchewan'],
            ],
            [
                'label' => 'Filter: only files',
                'description' => 'Same query restricted to `type=file` — handy for "find me the PDF".',
                'feature' => 'phase 1 + facets',
                'params' => $base + ['q' => '', 'filter' => ['type' => 'file']],
            ],
            [
                'label' => 'Sort by file size',
                'description' => 'Empty query + sort descending. Surfaces the biggest indexed binaries.',
                'feature' => 'sort',
                'params' => $base + ['q' => '', 'sort' => 'fileSize:desc'],
            ],
            [
                'label' => 'Pagination',
                'description' => 'Empty query → all docs, walk pages with Prev / Next at the bottom.',
                'feature' => 'pagination',
                'params' => $base + ['q' => '', 'page' => 1],
            ],
            [
                'label' => 'Hybrid (semantic + keyword)',
                'description' => $hasEmbedder
                    ? 'Vector + keyword blend — finds docs even when the wording is paraphrased.'
                    : 'Needs an embedder configured. The toggle stays a no-op on sites without one.',
                'feature' => 'phase 3' . ($hasEmbedder ? '' : ' (no embedder)'),
                'params' => $base + ['q' => 'how do I reset my password', 'hybrid' => 1],
            ],
            [
                'label' => 'RAG: ask the site',
                'description' => $hasRag
                    ? 'LLM-grounded answer with cited sources, retrieval bias toward the question topic.'
                    : 'Needs a RAG provider configured. Click anyway to see the "disabled" status.',
                'feature' => 'phase 4' . ($hasRag ? '' : ' (no provider)'),
                'params' => $base + ['ask' => 'What is this site about?'],
            ],
        ];

        // Compute the URL for each preset and inline it. Templates then
        // render a simple <a href="{ex.url}">{ex.label}</a> instead of
        // re-running buildUriFromRoute() per row.
        $base = (string)$this->backendUriBuilder->buildUriFromRoute(
            'system_wsmeilisearch',
            ['action' => 'test'],
        );
        foreach ($examples as &$ex) {
            $ex['url'] = $base . '&' . http_build_query($ex['params']);
        }
        return $examples;
    }

    /**
     * Shared tab-nav URLs. The TabNav partial wants indexUrl / testUrl /
     * diagnoseUrl on every action; pulling them out keeps each action
     * method's assignMultiple readable.
     *
     * Also extracts the CSRF token as a separate value. The Test.html GET
     * forms need it as a hidden <input> because HTML5 spec discards the
     * action URL's query string on form GET submit — the token would never
     * reach the server otherwise, triggering an unauthenticated redirect
     * through /typo3/main and the well-known "doubled BE chrome" bug.
     *
     * @return array<string,string>
     */
    private function commonTabUrls(): array
    {
        $indexUrl = (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch');
        $testUrl = (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'test']);
        $diagnoseUrl = (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'diagnose']);
        $helpdocsUrl = (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'helpdocs']);
        parse_str((string)parse_url($testUrl, PHP_URL_QUERY), $query);
        return [
            'indexUrl' => $indexUrl,
            'testUrl' => $testUrl,
            'diagnoseUrl' => $diagnoseUrl,
            'helpdocsUrl' => $helpdocsUrl,
            'token' => (string)($query['token'] ?? ''),
        ];
    }

    private function helpdocsAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        // The folder-importer form uses TYPO3's element-browser modal;
        // our small handler wires the postMessage back into the input.
        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@wapplersystems/meilisearch/folder-picker.js'),
        );

        // Aggregate the configured sourceRoot across sites so the import
        // form starts with a sensible path the operator usually wants.
        // First non-empty wins; stays empty if no site has it configured.
        $defaultSourceRoot = '';
        $knownLanguages = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configured = trim((string)$site->getSettings()->get('meilisearch.helpdoc.sourceRoot', ''));
            if ($configured !== '' && $defaultSourceRoot === '') {
                $defaultSourceRoot = $configured;
            }
            foreach ($site->getAllLanguages() as $lang) {
                $id = $lang->getLanguageId();
                $knownLanguages[$id] = $knownLanguages[$id] ?? sprintf('%d — %s', $id, $lang->getTitle());
            }
        }
        ksort($knownLanguages);

        $stats = $this->helpDocRepository->stats();
        // Augment each language row with its TYPO3 label (uses the first
        // site that has the language declared) so the template can
        // show "0 — Deutsch" instead of just "0".
        foreach ($stats['languages'] as $langId => &$row) {
            $row['label'] = $knownLanguages[$langId] ?? (string)$langId;
        }
        unset($row);

        // Deep-link into the standard List module for browse/edit, pid=0
        // since the importer writes helpdocs at root.
        $listEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('web_list', [
            'id' => 0,
            'table' => HelpDocRepository::HELPDOC_TABLE,
        ]);

        // List registered importers so the template can show a sidebar
        // of "what can I import here" — and so adding a new importer is
        // visible to operators without a template change.
        $importers = [];
        foreach ($this->importerRegistry->all() as $importer) {
            $importers[] = [
                'name' => $importer->name(),
                'label' => $importer->label(),
                'description' => $importer->description(),
                'fields' => $importer->describeFields(),
            ];
        }

        // The default FAL target folder for uploaded files — informational
        // placeholder in the BE forms so operators see what they get when
        // they leave the picker empty.
        $defaultTargetFolder = HelpDocRepository::DEFAULT_TARGET_FOLDER;
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configured = trim((string)$site->getSettings()->get('meilisearch.helpdoc.fileadminFolder', ''));
            if ($configured !== '') {
                $defaultTargetFolder = $configured;
                break;
            }
        }

        $moduleTemplate->assignMultiple([
            'stats' => $stats,
            'knownLanguages' => $knownLanguages,
            'defaultSourceRoot' => $defaultSourceRoot,
            'defaultTargetFolder' => $defaultTargetFolder,
            'defaultLangDir' => 'de',
            'listEditUrl' => $listEditUrl,
            'importers' => $importers,
            // Single endpoint for every registered importer — slug
            // travels as a hidden `_importer` field in the form.
            'runImporterUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'runImporter']),
            // CSRF-tokened URL for TYPO3's standard folder element-browser.
            // Built server-side because the BE route is token-protected;
            // the JS module just opens this URL as an iframe modal.
            'folderBrowserUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('wizard_element_browser'),
            'purgeUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'purgeHelpdocs']),
            ...$this->commonTabUrls(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/HelpDocs');
    }

    /**
     * Generic dispatcher for every registered HelpDocSourceImporter.
     * The form carries a hidden `_importer` field with the slug; this
     * action looks the importer up in the registry, maps form values
     * onto the importer's describeFields() schema, and runs it. Replaces
     * the five near-identical actions that used to bind a single
     * importer each.
     */
    private function runImporterAction(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->redirectToHelpdocs();
        }
        $body = (array)$request->getParsedBody();
        $slug = trim((string)($body['_importer'] ?? ''));
        if ($slug === '' || !$this->importerRegistry->has($slug)) {
            $this->addFlash(sprintf('Unknown importer "%s".', $slug), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToHelpdocs();
        }
        $importer = $this->importerRegistry->get($slug);

        try {
            $config = $this->buildImporterConfig($importer, $body, $request->getUploadedFiles());
        } catch (\Throwable $e) {
            $this->addFlash(sprintf('%s: %s', $importer->label(), $e->getMessage()), ContextualFeedbackSeverity::ERROR);
            return $this->redirectToHelpdocs();
        }

        try {
            $result = $importer->import($config);
            $message = sprintf(
                '%s: imported %d, skipped %d, media attached %d. Run reindex to push them to Meilisearch.',
                $importer->label(),
                $result->imported,
                $result->skipped,
                $result->mediaCopied,
            );
            // Surface the first few per-item errors (URL fetches, …) so the
            // operator sees what broke without digging into the log.
            $errors = (array)($result->extras['errors'] ?? []);
            if ($errors !== []) {
                $message .= ' First errors: ' . implode(' | ', array_slice($errors, 0, 3));
            }
            $severity = $result->imported === 0
                ? ContextualFeedbackSeverity::WARNING
                : ($errors !== [] || $result->skipped > 0
                    ? ContextualFeedbackSeverity::WARNING
                    : ContextualFeedbackSeverity::OK);
            $this->addFlash($message, $severity);
        } catch (\Throwable $e) {
            $this->addFlash(sprintf('%s failed: %s', $importer->label(), $e->getMessage()), ContextualFeedbackSeverity::ERROR);
        }
        return $this->redirectToHelpdocs();
    }

    /**
     * Map form values onto the importer's describeFields() schema. Per
     * field type:
     *   - file      → PSR-7 UploadedFile from $uploadedFiles[$name]
     *   - checkbox  → bool (the form ships a hidden=0 plus checkbox=1
     *                 marker so absent==unchecked, see ImporterField.html)
     *   - text/textarea/select/language/folder → trimmed string passed
     *                 through; importer casts to int as needed
     *
     * Required fields throw with a friendly label so the flash message
     * tells the operator which input they forgot.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $uploadedFiles
     * @return array<string, mixed>
     */
    private function buildImporterConfig(
        HelpDocSourceImporter $importer,
        array $body,
        array $uploadedFiles,
    ): array {
        $config = [];
        foreach ($importer->describeFields() as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? 'text');
            $label = (string)($field['label'] ?? $name);
            $required = !empty($field['required']);

            if ($type === 'file') {
                $upload = $uploadedFiles[$name] ?? null;
                if ($upload instanceof UploadedFileInterface && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                    $config[$name] = $upload;
                } elseif ($required) {
                    throw new \RuntimeException(sprintf('"%s" is required.', $label));
                }
                continue;
            }

            if ($type === 'checkbox') {
                // ImporterField.html renders a hidden=0 input before the
                // checkbox=1 input so the form always carries the field;
                // last-wins parsing gives us "1" or "0".
                if (array_key_exists($name, $body)) {
                    $config[$name] = (string)$body[$name] === '1';
                } else {
                    $config[$name] = (bool)($field['default'] ?? false);
                }
                continue;
            }

            $value = $body[$name] ?? $field['default'] ?? '';
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($required && ($value === '' || $value === null)) {
                throw new \RuntimeException(sprintf('"%s" is required.', $label));
            }
            $config[$name] = $value;
        }
        return $config;
    }

    private function purgeHelpdocsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->redirectToHelpdocs();
        }
        $body = (array)$request->getParsedBody();
        $languageId = (int)($body['language'] ?? -1);
        if ($languageId < 0) {
            $this->addFlash('Language is required.', ContextualFeedbackSeverity::ERROR);
            return $this->redirectToHelpdocs();
        }
        $confirmed = isset($body['confirm']) && (string)$body['confirm'] === '1';
        if (!$confirmed) {
            $this->addFlash('Purge skipped — confirmation checkbox was not ticked.', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToHelpdocs();
        }
        try {
            $deleted = $this->helpDocRepository->purgeLanguage($languageId);
            $this->addFlash(
                sprintf('Purged %d helpdoc row(s) for language %d. Re-run reindex so Meilisearch drops the orphaned doc IDs too.', $deleted, $languageId),
                ContextualFeedbackSeverity::OK,
            );
        } catch (\Throwable $e) {
            $this->addFlash('Purge failed: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return $this->redirectToHelpdocs();
    }

    private function redirectToHelpdocs(): ResponseInterface
    {
        return new RedirectResponse(
            (string)$this->backendUriBuilder->buildUriFromRoute('system_wsmeilisearch', ['action' => 'helpdocs']),
        );
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

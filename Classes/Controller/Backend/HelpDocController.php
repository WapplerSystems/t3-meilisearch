<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use WapplerSystems\Meilisearch\Controller\Backend\Support\BackendContext;
use WapplerSystems\Meilisearch\Controller\Backend\Support\SiteOverviewProvider;
use WapplerSystems\Meilisearch\Service\Import\HelpDocRepository;
use WapplerSystems\Meilisearch\Service\Import\HelpDocSourceImporter;
use WapplerSystems\Meilisearch\Service\Import\SourceImporterRegistry;

/**
 * The Help-docs tab + every action that mutates tx_wsmeilisearch_helpdoc:
 *
 *   - helpdocs    GET   stats table + one form per registered importer
 *                       + purge-by-language form + List-module deep link
 *   - runImporter POST  generic dispatcher; reads the importer slug
 *                       from the form's hidden `_importer` field and
 *                       maps the rest onto the importer's
 *                       describeFields() schema (see buildImporterConfig)
 *   - purgeHelpdocs POST destructive purge for a single language with
 *                       a confirmation checkbox guard
 *
 * Split out of OverviewController so importer-registry + repository +
 * folder-picker JS module live next to the only actions that use them.
 */
final class HelpDocController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly SourceImporterRegistry $importerRegistry,
        private readonly HelpDocRepository $helpDocRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendContext $context,
        private readonly SiteOverviewProvider $siteOverviewProvider,
    ) {}

    public function handle(ServerRequestInterface $request, string $action): ResponseInterface
    {
        return match ($action) {
            'runImporter'   => $this->runImporter($request),
            'purgeHelpdocs' => $this->purge($request),
            default         => $this->helpdocs($request),
        };
    }

    private function helpdocs(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        // The folder-importer form uses TYPO3's element-browser modal;
        // our small handler wires the postMessage back into the input.
        $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
            JavaScriptModuleInstruction::create('@wapplersystems/meilisearch/folder-picker.js'),
        );

        // BE-form placeholders / defaults pulled from whichever site
        // has them set first. Iteration moved to SiteOverviewProvider
        // so the three lookups (sourceRoot, fileadminFolder,
        // knownLanguages) don't each open their own getAllSites loop.
        $defaultSourceRoot = $this->siteOverviewProvider->firstNonEmpty('meilisearch.helpdoc.sourceRoot');
        $defaultTargetFolder = $this->siteOverviewProvider->firstNonEmpty(
            'meilisearch.helpdoc.fileadminFolder',
            HelpDocRepository::DEFAULT_TARGET_FOLDER,
        );
        $knownLanguages = $this->siteOverviewProvider->knownLanguages();

        $stats = $this->helpDocRepository->stats();
        // Augment each language row with its TYPO3 label so the
        // template can show "0 — Deutsch" instead of just "0".
        foreach ($stats['languages'] as $langId => &$row) {
            $row['label'] = $knownLanguages[$langId] ?? (string)$langId;
        }
        unset($row);

        // Deep-link into the standard List module for browse/edit.
        $listEditUrl = (string)$this->backendUriBuilder->buildUriFromRoute('web_list', [
            'id' => 0,
            'table' => HelpDocRepository::HELPDOC_TABLE,
        ]);

        // Render the importer cards: each registered HelpDocSourceImporter
        // contributes its own form via describeFields(). Adding a new
        // importer doesn't require any template change here.
        $importers = [];
        foreach ($this->importerRegistry->all() as $importer) {
            $importers[] = [
                'name' => $importer->name(),
                'label' => $importer->label(),
                'description' => $importer->description(),
                'fields' => $importer->describeFields(),
            ];
        }

        $moduleTemplate->assignMultiple([
            'stats' => $stats,
            'knownLanguages' => $knownLanguages,
            'defaultSourceRoot' => $defaultSourceRoot,
            'defaultTargetFolder' => $defaultTargetFolder,
            'defaultLangDir' => 'de',
            'listEditUrl' => $listEditUrl,
            'importers' => $importers,
            'runImporterUrl' => $this->context->route('runImporter'),
            // CSRF-tokened URL for TYPO3's standard folder element-browser.
            // Built server-side because the BE route is token-protected;
            // the JS module just opens this URL as an iframe modal.
            'folderBrowserUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('wizard_element_browser'),
            'purgeUrl' => $this->context->route('purgeHelpdocs'),
            ...$this->context->tabNavData(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Overview/HelpDocs');
    }

    /**
     * Generic dispatcher for every registered HelpDocSourceImporter.
     * The form carries a hidden `_importer` field with the slug; this
     * action looks the importer up in the registry, maps form values
     * onto the importer's describeFields() schema, and runs it.
     */
    private function runImporter(ServerRequestInterface $request): ResponseInterface
    {
        if ($wrong = $this->context->requirePost($request, 'helpdocs')) {
            return $wrong;
        }
        $body = (array)$request->getParsedBody();
        $slug = trim((string)($body['_importer'] ?? ''));
        if ($slug === '' || !$this->importerRegistry->has($slug)) {
            $this->context->addFlash(sprintf('Unknown importer "%s".', $slug), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('helpdocs');
        }
        $importer = $this->importerRegistry->get($slug);

        try {
            $config = $this->buildImporterConfig($importer, $body, $request->getUploadedFiles());
        } catch (\Throwable $e) {
            $this->context->addFlash(sprintf('%s: %s', $importer->label(), $e->getMessage()), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('helpdocs');
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
            $severity = $result->imported === 0 || $errors !== [] || $result->skipped > 0
                ? ContextualFeedbackSeverity::WARNING
                : ContextualFeedbackSeverity::OK;
            $this->context->addFlash($message, $severity);
        } catch (\Throwable $e) {
            $this->context->addFlash(sprintf('%s failed: %s', $importer->label(), $e->getMessage()), ContextualFeedbackSeverity::ERROR);
        }
        return $this->context->redirect('helpdocs');
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

    private function purge(ServerRequestInterface $request): ResponseInterface
    {
        if ($wrong = $this->context->requirePost($request, 'helpdocs')) {
            return $wrong;
        }
        $body = (array)$request->getParsedBody();
        $languageId = (int)($body['language'] ?? -1);
        if ($languageId < 0) {
            $this->context->addFlash('Language is required.', ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('helpdocs');
        }
        $confirmed = isset($body['confirm']) && (string)$body['confirm'] === '1';
        if (!$confirmed) {
            $this->context->addFlash('Purge skipped — confirmation checkbox was not ticked.', ContextualFeedbackSeverity::WARNING);
            return $this->context->redirect('helpdocs');
        }
        try {
            $deleted = $this->helpDocRepository->purgeLanguage($languageId);
            $this->context->addFlash(
                sprintf('Purged %d helpdoc row(s) for language %d. Re-run reindex so Meilisearch drops the orphaned doc IDs too.', $deleted, $languageId),
                ContextualFeedbackSeverity::OK,
            );
        } catch (\Throwable $e) {
            $this->context->addFlash('Purge failed: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }
        return $this->context->redirect('helpdocs');
    }
}

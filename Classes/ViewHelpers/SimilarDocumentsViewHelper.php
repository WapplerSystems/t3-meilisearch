<?php
declare(strict_types=1);

namespace WapplerSystems\Meilisearch\ViewHelpers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;
use WapplerSystems\Meilisearch\Service\SimilarDocumentsService;

/**
 * Server-rendered "related content" list for a given document id.
 * Returns the hit array; the calling template renders the layout.
 *
 * Usage:
 *   <ws:similarDocuments sourceId="help-3684" limit="5" types="knowledge_resource" as="related">
 *     <f:if condition="{related}">
 *       <h2>Verwandte Themen</h2>
 *       <ul>
 *         <f:for each="{related}" as="hit">
 *           <li><a href="{hit.uri}">{hit.title}</a></li>
 *         </f:for>
 *       </ul>
 *     </f:if>
 *   </ws:similarDocuments>
 *
 * Without `as`, the ViewHelper returns the hits array directly, which
 * is useful when the caller wants to use them in a {f:variable}
 * assignment rather than inline.
 *
 * Filtering / language scope mirror the SimilarEndpoint middleware
 * (TYPO3 v14 path: SiteLanguage::getLocale()->getLanguageCode() — see
 * feedback_v14_sitelanguage_locale.md), and FE access control runs
 * via SimilarDocumentsService → AccessControlFilter against the
 * current PSR-7 request.
 */
final class SimilarDocumentsViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    protected $escapeOutput = false;
    protected $escapeChildren = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('sourceId', 'string', 'Document id (e.g. "help-3684") to find similars for.', true);
        $this->registerArgument('limit', 'int', 'Maximum number of hits to return.', false, 5);
        $this->registerArgument('types', 'string', 'Comma-separated type allowlist (e.g. "knowledge_resource,page"). Empty = any type.', false, '');
        $this->registerArgument('as', 'string', 'Variable name to expose hits under. When empty, the array is returned directly.', false, '');
    }

    /**
     * @param array{sourceId:string, limit:int, types:string, as:string} $arguments
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): mixed {
        $sourceId = trim((string)($arguments['sourceId'] ?? ''));
        $as = trim((string)$arguments['as']);
        if ($sourceId === '') {
            return $as === '' ? [] : $renderChildrenClosure();
        }

        // Pull the active ServerRequest. v14 Fluid carries it on the
        // RenderingContext via the request attribute container; older
        // standalone-Fluid setups fall back to the global request.
        // GeneralUtility::makeInstance keeps DI working in either case.
        $serverRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$serverRequest instanceof ServerRequestInterface) {
            return $as === '' ? [] : $renderChildrenClosure();
        }
        $site = $serverRequest->getAttribute('site');
        if (!$site instanceof Site) {
            return $as === '' ? [] : $renderChildrenClosure();
        }

        $types = [];
        $rawTypes = trim((string)$arguments['types']);
        if ($rawTypes !== '') {
            $types = array_values(array_filter(array_map('trim', explode(',', $rawTypes))));
        }
        $language = $serverRequest->getAttribute('language');
        $iso = '';
        $languageId = null;
        if ($language instanceof SiteLanguage) {
            $languageId = $language->getLanguageId();
            $iso = strtolower((string)$language->getLocale()->getLanguageCode());
        }

        $service = GeneralUtility::makeInstance(SimilarDocumentsService::class);
        $hits = $service->findSimilar($site, $sourceId, [
            'limit' => (int)$arguments['limit'],
            'types' => $types,
            'language' => $languageId,
            'contentLanguageIso' => $iso,
            'accessRequest' => $serverRequest,
        ]);

        if ($as === '') {
            return $hits;
        }
        $variableProvider = $renderingContext->getVariableProvider();
        $variableProvider->add($as, $hits);
        $output = $renderChildrenClosure();
        $variableProvider->remove($as);
        return $output;
    }
}

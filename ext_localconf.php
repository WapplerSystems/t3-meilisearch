<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use WapplerSystems\Meilisearch\Controller\RagController;
use WapplerSystems\Meilisearch\Controller\SearchController;
use WapplerSystems\Meilisearch\DataHandling\RecordChangeListener;

ExtensionUtility::configurePlugin(
    'WsMeilisearch',
    'Search',
    [SearchController::class => 'search,results'],
    [SearchController::class => 'search,results']
);

// RAG plugin (Phase 4). Separate plugin so sites can use search without
// committing to RAG (which costs LLM tokens per question).
ExtensionUtility::configurePlugin(
    'WsMeilisearch',
    'Rag',
    [RagController::class => 'form,ask,reset'],
    [RagController::class => 'form,ask,reset']
);

// DataHandler hooks — keep Meilisearch documents in sync on backend writes.
// Migrate to PSR-14 events once TYPO3 core ships record lifecycle events.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = RecordChangeListener::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = RecordChangeListener::class;

// The plugin renders results non-cacheable and uses GET forms — so the form
// action URL is just the page URL and the plugin args ride as form fields.
// GET form submission discards the action URL's query string, which means
// neither the static (action, controller) nor the dynamic (q, page, filters)
// args can be pre-baked into a cHash. Exclude the entire plugin namespace
// from cHash so the resulting URL is accepted without one.
//
// Safety: action / controller values are still validated by Extbase against
// the registered controller actions list — a forged URL cannot invoke an
// arbitrary controller, only the ones in configurePlugin() above.
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] = array_merge(
    $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'] ?? [],
    ['^tx_wsmeilisearch_search', '^tx_wsmeilisearch_rag']
);

// Tika text extraction cache — keyed by file content sha1, so identical
// content across multiple sys_file rows extracts exactly once and content
// changes invalidate naturally (new sha1 → new key). File-backend so a
// reindex after a deploy reuses extractions; flush via the system group.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ws_meilisearch_tika'] ??= [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'options' => [],
    'groups' => ['system'],
];

// Per-site Meilisearch metadata cache (doc count + active embedder). 60s
// TTL keeps the Overview / Diagnostics BE tabs snappy on multi-site
// installs without burning a roundtrip per site per page render. Cache
// is invalidated explicitly after Reindex and Re-push Embedder actions
// (see IndexMetadataProvider). Database backend so the entries survive
// across requests; the volume is tiny (one row per site).
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ws_meilisearch_meta'] ??= [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'options' => ['defaultLifetime' => 60],
    'groups' => ['system'],
];
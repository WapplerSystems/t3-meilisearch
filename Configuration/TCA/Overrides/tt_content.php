<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

$searchCType = ExtensionUtility::registerPlugin(
    'WsMeilisearch',
    'Search',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.search.title',
    'content-elements-searchform',
    'plugins',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.search.description'
);

// Per-instance overrides for the Search plugin — visible facets, perPage,
// default sort, restrictToCurrentLanguage. All optional; empty inherits
// from Site Settings. See Configuration/FlexForms/Search.xml and the
// SearchController for the merge logic.
ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    'pi_flexform',
    $searchCType,
    'after:subheader',
);
ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:ws_meilisearch/Configuration/FlexForms/Search.xml',
    $searchCType,
);

ExtensionUtility::registerPlugin(
    'WsMeilisearch',
    'Rag',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.rag.title',
    'content-elements-chatbot',
    'plugins',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.rag.description'
);
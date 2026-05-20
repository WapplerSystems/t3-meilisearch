<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

ExtensionUtility::registerPlugin(
    'WsMeilisearch',
    'Search',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.search.title',
    'content-elements-searchform',
    'plugins',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.search.description'
);

ExtensionUtility::registerPlugin(
    'WsMeilisearch',
    'Rag',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.rag.title',
    'content-elements-chatbot',
    'plugins',
    'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:plugin.rag.description'
);
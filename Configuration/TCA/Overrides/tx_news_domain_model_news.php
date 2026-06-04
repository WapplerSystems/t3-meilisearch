<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// EXT:news is a soft dependency (composer "suggest"). Skip the override
// gracefully when news isn't loaded so the extension stays installable
// against minimal TYPO3 sites.
if (!ExtensionManagementUtility::isLoaded('news')) {
    return;
}

$columns = [
    'tx_wsmeilisearch_boost' => [
        'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.label',
        'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'default' => 2,
            'items' => [
                ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.veryLow',  'value' => 0],
                ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.low',      'value' => 1],
                ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.normal',   'value' => 2],
                ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.high',     'value' => 3],
                ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.veryHigh', 'value' => 4],
            ],
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('tx_news_domain_model_news', $columns);
ExtensionManagementUtility::addToAllTCAtypes(
    'tx_news_domain_model_news',
    '--div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.tab,tx_wsmeilisearch_boost',
);
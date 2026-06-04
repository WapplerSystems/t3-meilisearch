<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Editor-controlled relevance boost. Composes with the per-type Site
 * Settings multiplier (meilisearch.boosts.types.<type>) into the
 * document's `boost` field via BoostCalculator. Only influences ranking
 * once "boost:desc" is added to meilisearch.defaults.rankingRules.
 *
 * Default is 2 (normal = multiplier 1.0) so legacy rows behave exactly
 * as before. See BoostCalculator::ENUM_TO_MULTIPLIER for the mapping.
 */
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

ExtensionManagementUtility::addTCAcolumns('pages', $columns);
ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    '--div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.tab,tx_wsmeilisearch_boost',
);
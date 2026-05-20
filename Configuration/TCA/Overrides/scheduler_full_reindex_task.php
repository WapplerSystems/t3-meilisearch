<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use WapplerSystems\Meilisearch\Task\FullReindexTask;

ExtensionManagementUtility::addTCAcolumns('tx_scheduler_task', [
    'tx_wsmeilisearch_site_identifier' => [
        'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.site_identifier',
        'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.site_identifier.description',
        'config' => [
            'type' => 'input',
            'size' => 30,
            'eval' => 'trim',
        ],
    ],
    'tx_wsmeilisearch_rebuild' => [
        'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.rebuild',
        'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.rebuild.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
    'tx_wsmeilisearch_skip_embedder' => [
        'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.skip_embedder',
        'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.skip_embedder.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);

ExtensionManagementUtility::addRecordType(
    [
        'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.title',
        'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.fullReindex.description',
        'value' => FullReindexTask::class,
        'icon' => 'tx-scheduler-task',
        'group' => 'scheduler',
    ],
    '
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:scheduler.tabs.task,
            tasktype,
            description,
            tx_wsmeilisearch_site_identifier,
            tx_wsmeilisearch_rebuild,
            tx_wsmeilisearch_skip_embedder,
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:scheduler.tabs.timing,
            execution_details,
            nextexecution,
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:scheduler.tabs.access,
            disable,
    ',
    [],
    '',
    'tx_scheduler_task'
);

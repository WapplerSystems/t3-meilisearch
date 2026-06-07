<?php
declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use WapplerSystems\Meilisearch\Task\RunRagTestsTask;

// The tx_wsmeilisearch_site_identifier column on tx_scheduler_task is
// added by scheduler_full_reindex_task.php — both task types share the
// same "which site" semantics, no need to declare a sibling column.

ExtensionManagementUtility::addRecordType(
    [
        'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.ragTests.title',
        'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:task.ragTests.description',
        'value' => RunRagTestsTask::class,
        'icon' => 'tx-scheduler-task',
        'group' => 'scheduler',
    ],
    '
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:scheduler.tabs.task,
            tasktype,
            description,
            tx_wsmeilisearch_site_identifier,
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:scheduler.tabs.timing,
            execution_details,
            nextexecution,
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:scheduler.tabs.access,
            disable,
    ',
    [],
    '',
    'tx_scheduler_task',
);

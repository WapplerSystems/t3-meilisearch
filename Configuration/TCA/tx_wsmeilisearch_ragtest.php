<?php
declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.title',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/apps/apps-pagetree-folder-default.svg',
        // Surface the most useful columns in the List module without
        // forcing the operator to open every record.
        'searchFields' => 'title,question,expected_answer',
        'requestUpdate' => 'last_status',
    ],
    'palettes' => [
        'lastrun' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.palette.lastrun',
            'showitem' => 'last_status, last_score, --linebreak--, last_run_at, --linebreak--, last_actual_answer, --linebreak--, last_error',
        ],
    ],
    'types' => [
        '0' => [
            'showitem' =>
                'title, question, expected_answer,'
                . ' --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.tab.config,'
                . ' site_identifier, similarity_threshold, expected_doc_ids, context_requirement,'
                . ' --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.tab.lastrun,'
                . ' --palette--;;lastrun,'
                . ' --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_core.xlf:tabs.access,'
                . ' hidden',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.title.label',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.title.description',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'required' => true,
            ],
        ],
        'question' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.question',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.question.description',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 4,
                'required' => true,
            ],
        ],
        'expected_answer' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.expected_answer',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.expected_answer.description',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 6,
                'required' => true,
            ],
        ],
        'similarity_threshold' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.threshold',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.threshold.description',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 6,
                'range' => ['lower' => 0, 'upper' => 1],
                'default' => 0.85,
            ],
        ],
        'site_identifier' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.site',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.site.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'placeholder' => 'main',
            ],
        ],
        'expected_doc_ids' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.expected_doc_ids',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.expected_doc_ids.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'context_requirement' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.context_requirement',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.context_requirement.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'last_status' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.last_status',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'size' => 16,
            ],
        ],
        'last_score' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.last_score',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'readOnly' => true,
                'size' => 6,
            ],
        ],
        'last_run_at' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.last_run_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'format' => 'datetime',
            ],
        ],
        'last_actual_answer' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.last_actual_answer',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
                'cols' => 60,
                'rows' => 6,
            ],
        ],
        'last_error' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:ragtest.last_error',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
                'cols' => 60,
                'rows' => 3,
            ],
        ],
    ],
];

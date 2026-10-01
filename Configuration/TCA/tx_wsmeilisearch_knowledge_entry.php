<?php
declare(strict_types=1);

/**
 * Hand-written knowledge for the chat assistant, maintained in the backend
 * module "Chatbot-Wissen" and indexed by KnowledgeEntrySchemaProvider.
 * Plain text instead of RTE on purpose: the language model reads the body
 * verbatim, and markup would only be noise in its context.
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.table',
        'label' => 'title',
        'descriptionColumn' => 'notes',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'searchFields' => 'title,body,keywords,notes',
        'iconfile' => 'EXT:ws_meilisearch/Resources/Public/Icons/Extension.svg',
        'default_sortby' => 'title ASC',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'sys_language_uid' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.sys_language_uid',
            'config' => ['type' => 'language'],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.l10n_parent',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_wsmeilisearch_knowledge_entry',
                'foreign_table_where' => 'AND {#tx_wsmeilisearch_knowledge_entry}.{#pid}=###CURRENT_PID### AND {#tx_wsmeilisearch_knowledge_entry}.{#sys_language_uid} IN (-1,0)',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'size' => 1,
                'maxitems' => 1,
                'minitems' => 0,
                'default' => 0,
            ],
        ],
        'l10n_diffsource' => [
            'config' => ['type' => 'passthrough'],
        ],
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'starttime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.starttime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'endtime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.endtime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.title',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.title.description',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'body' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.body',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.body.description',
            'config' => [
                'type' => 'text',
                'rows' => 12,
                'cols' => 60,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'keywords' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.keywords',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.keywords.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 60,
                'eval' => 'trim',
            ],
        ],
        'notes' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.notes',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.notes.description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 60,
                'eval' => 'trim',
            ],
        ],
        'source_conversation' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.source_conversation',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.source_conversation.description',
            'config' => [
                'type' => 'input',
                'size' => 32,
                'max' => 32,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'tx_wsmeilisearch_boost' => [
            'exclude' => true,
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.tx_wsmeilisearch_boost',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.tx_wsmeilisearch_boost.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 3,
                'items' => [
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.veryLow',  'value' => 0],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.low',      'value' => 1],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.normal',   'value' => 2],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.high',     'value' => 3],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:tca.boost.veryHigh', 'value' => 4],
                ],
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'sys_language_uid, l10n_parent, hidden,
                --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.tab.content, title, body, keywords,
                --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.tab.access, starttime, endtime, tx_wsmeilisearch_boost,
                --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeEntry.tab.internal, notes, source_conversation',
        ],
    ],
];

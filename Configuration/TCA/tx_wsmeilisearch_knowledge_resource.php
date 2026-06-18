<?php
declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.title',
        'label' => 'title',
        'descriptionColumn' => 'abstract',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'origUid' => 't3_origuid',
        'searchFields' => 'identifier,title,abstract,body',
        'iconfile' => 'EXT:ws_meilisearch/Resources/Public/Icons/Extension.svg',
        // Records ARE editable in the BE List module — editors typically
        // tweak the boost field or toggle hidden=1 to suppress an outdated
        // topic from search results. They should NOT create new rows by
        // hand (the next importer run purges + rebuilds the row's language
        // scope, throwing away the entry). The BE "Help docs" module tab
        // shows the importer-triggered workflow and the deep-link into
        // List for individual edits.
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'sys_language_uid' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => ['type' => 'language'],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_wsmeilisearch_knowledge_resource',
                'size' => 1,
                'maxitems' => 1,
                'minitems' => 0,
                'default' => 0,
            ],
        ],
        'l10n_source' => [
            'config' => ['type' => 'passthrough'],
        ],
        'l10n_diffsource' => [
            'config' => ['type' => 'passthrough'],
        ],
        't3_origuid' => [
            'config' => ['type' => 'passthrough'],
        ],
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.visible',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'identifier' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.identifier',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 190,
                'eval' => 'trim,required',
                'readOnly' => true,
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.heading',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 512,
                'eval' => 'trim',
            ],
        ],
        'abstract' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.abstract',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 60,
            ],
        ],
        'body' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.body',
            'config' => [
                'type' => 'text',
                'rows' => 15,
                'cols' => 60,
            ],
        ],
        'resource_type' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.resourceType',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'concept', 'value' => 'concept'],
                    ['label' => 'task', 'value' => 'task'],
                    ['label' => 'reference', 'value' => 'reference'],
                ],
                'default' => 'concept',
            ],
        ],
        'parent_identifier' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.parentIdentifier',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 190,
                'eval' => 'trim',
            ],
        ],
        'source_path' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.sourcePath',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 512,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'media' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:knowledgeResource.media',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'common-media-types',
            ],
        ],
        'tx_wsmeilisearch_boost' => [
            'exclude' => true,
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
    ],
    'types' => [
        '0' => [
            'showitem' => 'sys_language_uid, l10n_parent, hidden,
                --div--;Content, identifier, resource_type, title, abstract, body, media,
                --div--;Structure, parent_identifier, source_path,
                --div--;Search, tx_wsmeilisearch_boost',
        ],
    ],
];
<?php
declare(strict_types=1);

defined('TYPO3') or die();

// Editorial half of the search dictionary. The curated base list lives in
// the site's settings.yaml; these records are what redaction may add
// without a deploy, plus the candidates DictionaryMineCommand proposes.
// SearchConfigurationProvider merges both when the index settings are
// pushed, so a saved record reaches the engine on the next
// `ws_meilisearch:apply-settings` (or the next reindex).
return [
    'ctrl' => [
        'title' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.title',
        'label' => 'term',
        'label_alt' => 'kind,state',
        'label_alt_force' => true,
        'default_sortby' => 'state ASC, hits DESC, term ASC',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/actions/actions-book-open.svg',
        'searchFields' => 'term,replacements,evidence',
        // Kind decides whether `replacements` is meaningful at all, so the
        // form has to re-render when it changes.
        'requestUpdate' => 'kind',
    ],
    'types' => [
        '0' => [
            'showitem' =>
                'kind, term, replacements, state,'
                . ' --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.tab.origin,'
                . ' site_identifier, source, evidence, hits,'
                . ' --div--;LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_core.xlf:tabs.access,'
                . ' hidden',
        ],
    ],
    'columns' => [
        'kind' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.kind',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.kind.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.kind.synonym', 'value' => 'synonym'],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.kind.word', 'value' => 'word'],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.kind.stopword', 'value' => 'stopword'],
                ],
                'default' => 'synonym',
                'required' => true,
            ],
        ],
        'term' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.term',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.term.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 190,
                'required' => true,
                // Meilisearch matches synonyms case-insensitively but stores
                // them verbatim; lowercase keeps the list free of duplicates
                // that differ only in capitalisation.
                'eval' => 'trim,lower',
            ],
        ],
        'replacements' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.replacements',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.replacements.description',
            'displayCond' => 'FIELD:kind:=:synonym',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 4,
            ],
        ],
        'state' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.state',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.state.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.state.candidate', 'value' => 'candidate'],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.state.active', 'value' => 'active'],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.state.rejected', 'value' => 'rejected'],
                ],
                'default' => 'candidate',
                'required' => true,
            ],
        ],
        'site_identifier' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.site',
            'description' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.site.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'eval' => 'trim',
                'placeholder' => '*',
            ],
        ],
        'source' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.source',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.source.manual', 'value' => 'manual'],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.source.log', 'value' => 'log'],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.source.translation', 'value' => 'translation'],
                    ['label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.source.vocabulary', 'value' => 'vocabulary'],
                ],
                'default' => 'manual',
            ],
        ],
        'evidence' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.evidence',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 255,
                'readOnly' => true,
            ],
        ],
        'hits' => [
            'label' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:dictionary.hits',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
    ],
];

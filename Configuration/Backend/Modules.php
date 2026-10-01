<?php

declare(strict_types=1);

use WapplerSystems\Meilisearch\Controller\Backend\KnowledgeEntryController;
use WapplerSystems\Meilisearch\Controller\Backend\OverviewController;

return [
    'system_wsmeilisearch' => [
        'parent' => 'system',
        'access' => 'admin',
        'path' => '/module/system/meilisearch',
        'iconIdentifier' => 'module-wsmeilisearch',
        'labels' => [
            'title' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:module.overview.title',
            'shortDescription' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:module.overview.description',
        ],
        'routes' => [
            '_default' => [
                'target' => OverviewController::class . '::handleRequest',
            ],
        ],
    ],
    // Hand-written knowledge for the chat assistant. A module of its own,
    // outside the admin-only Meilisearch module, because the people who
    // write it are editors: they need table permissions on
    // tx_wsmeilisearch_knowledge_entry and this module in their group.
    'site_wsmeilisearch_knowledge' => [
        'parent' => 'site',
        'access' => 'user',
        'path' => '/module/site/chatbot-knowledge',
        'iconIdentifier' => 'module-wsmeilisearch',
        'labels' => [
            'title' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:module.knowledge.title',
            'shortDescription' => 'LLL:EXT:ws_meilisearch/Resources/Private/Language/locallang_be.xlf:module.knowledge.description',
        ],
        'routes' => [
            '_default' => [
                'target' => KnowledgeEntryController::class . '::handleRequest',
            ],
        ],
    ],
];

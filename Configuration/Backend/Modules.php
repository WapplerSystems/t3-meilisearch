<?php

declare(strict_types=1);

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
];

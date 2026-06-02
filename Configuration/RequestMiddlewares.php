<?php

declare(strict_types=1);

use WapplerSystems\Meilisearch\Middleware\RagStreamMiddleware;
use WapplerSystems\Meilisearch\Middleware\SearchFragmentEndpoint;
use WapplerSystems\Meilisearch\Middleware\SuggestEndpoint;

return [
    'frontend' => [
        'wapplersystems/meilisearch/rag-stream' => [
            'target' => RagStreamMiddleware::class,
            // Must run *after* site resolution so the request carries the
            // resolved Site attribute; runs *before* page resolution so
            // the streaming path doesn't trigger a TYPO3 404 lookup.
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
        'wapplersystems/meilisearch/suggest' => [
            'target' => SuggestEndpoint::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
        'wapplersystems/meilisearch/search-fragment' => [
            'target' => SearchFragmentEndpoint::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => ['typo3/cms-frontend/page-resolver'],
        ],
    ],
];

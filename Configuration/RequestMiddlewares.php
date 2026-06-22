<?php

declare(strict_types=1);

use WapplerSystems\Meilisearch\Middleware\KnowledgeResourceTopicMiddleware;
use WapplerSystems\Meilisearch\Middleware\RagStreamMiddleware;
use WapplerSystems\Meilisearch\Middleware\SearchFragmentEndpoint;
use WapplerSystems\Meilisearch\Middleware\SimilarEndpoint;
use WapplerSystems\Meilisearch\Middleware\SuggestEndpoint;

return [
    'frontend' => [
        'wapplersystems/meilisearch/rag-stream' => [
            'target' => RagStreamMiddleware::class,
            // Must run *after* site resolution so the request carries the
            // resolved Site attribute; runs *before* page resolution and
            // *before* the base-redirect-resolver so the unprefixed paths
            // (/_ws_meilisearch/…) aren't 404'd as "no language base".
            'after' => ['typo3/cms-frontend/site'],
            'before' => [
                'typo3/cms-frontend/page-resolver',
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
        'wapplersystems/meilisearch/suggest' => [
            'target' => SuggestEndpoint::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => [
                'typo3/cms-frontend/page-resolver',
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
        'wapplersystems/meilisearch/similar' => [
            'target' => SimilarEndpoint::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => [
                'typo3/cms-frontend/page-resolver',
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
        'wapplersystems/meilisearch/search-fragment' => [
            'target' => SearchFragmentEndpoint::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => [
                'typo3/cms-frontend/page-resolver',
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
        'wapplersystems/meilisearch/help-topic' => [
            // Serves /hilfe/<path> from the configured DITA-OT root which
            // lives outside public/. Runs after site resolution (so the
            // middleware can read meilisearch.knowledgeResource.sourceRoot from
            // the site settings) and before page resolution so the URL
            // never hits TYPO3's slug router.
            'target' => KnowledgeResourceTopicMiddleware::class,
            'after' => ['typo3/cms-frontend/site'],
            'before' => [
                'typo3/cms-frontend/page-resolver',
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];

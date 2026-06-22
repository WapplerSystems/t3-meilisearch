<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Meilisearch — Search, Hybrid & RAG',
    'description' => 'Meilisearch backend for TYPO3 v14: typo-tolerant full-text search across pages, news and FAL files (Tika-extracted), with hybrid keyword + semantic ranking, RAG-powered chat with cited sources, live suggestions, similar documents, content-language detection, zero-downtime reindex, and a backend analytics tab. SEAL-abstracted so the search backend stays swappable.',
    'category' => 'plugin',
    'author' => 'Sven Wappler',
    'author_email' => 'typo3@wappler.systems',
    'state' => 'beta',
    'version' => '14.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
    ],
];

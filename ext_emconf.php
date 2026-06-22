<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Meilisearch Search Backend',
    'description' => 'Meilisearch backend integration for TYPO3 via SEAL abstraction.',
    'category' => 'plugin',
    'author' => 'Sven Wappler',
    'author_email' => 'typo3@wappler.systems',
    'state' => 'alpha',
    'version' => '14.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
    ],
];

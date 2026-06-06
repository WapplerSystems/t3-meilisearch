<?php

/**
 * Import-map for JS modules shipped by this extension. TYPO3's BE
 * page renderer reads this so we can use the canonical
 * `@wapplersystems/meilisearch/...` specifier instead of bare paths.
 */
return [
    'dependencies' => ['backend'],
    'imports' => [
        '@wapplersystems/meilisearch/folder-picker.js' => 'EXT:ws_meilisearch/Resources/Public/JavaScript/folder-picker.js',
    ],
];

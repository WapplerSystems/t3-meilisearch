/**
 * On page load after a search submit, scroll the result list into view
 * so the visitor sees their hits without having to manually scroll
 * past the form + hero.
 *
 * Why this exists as a JS file rather than a CSS scroll-anchor or a
 * <form action="…#results">: HTML5 strips the fragment from a form's
 * action URL on GET submits. Setting location.hash here, *after*
 * navigation, side-steps that.
 *
 * Only fires when the URL carries an Extbase q parameter — never on
 * the bare /search page load, otherwise an empty <body> would scroll
 * past its own search box.
 */
(function () {
    'use strict';

    function shouldScroll() {
        const params = new URLSearchParams(window.location.search);
        // Match both Extbase-prefixed (tx_wsmeilisearch_search[q]) and
        // bare-key (q) usage so site packages with their own param
        // shapes work too.
        for (const key of params.keys()) {
            if (key === 'q' || key.endsWith('[q]')) {
                const v = params.get(key);
                if (v && v.trim() !== '') return true;
            }
        }
        return false;
    }

    function jump() {
        const target = document.getElementById('ws-meilisearch-results');
        if (target) {
            target.scrollIntoView({ block: 'start', behavior: 'auto' });
        }
    }

    if (!shouldScroll()) return;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', jump);
    } else {
        jump();
    }
})();

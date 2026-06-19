/**
 * Loading-state animation for the synchronous RAG search form.
 *
 * The form on /suche submits a regular GET request — the page reloads
 * once the LLM has finished generating, which can take 5–20 seconds
 * depending on the provider. Without feedback the user has no signal
 * the click registered, so they often re-click and double-fire the
 * query. This script swaps the submit button's contents to a spinner
 * + status text the moment the form is submitted and disables the
 * button to prevent the resubmit storm.
 *
 * State is reset on `pageshow` (covers the browser back/forward bfcache
 * path where the page is restored from the cache with the form still
 * in its spun-up state).
 *
 * Wires onto any `<form data-ws-meilisearch-rag-form>` — the search
 * partial sets that attribute. CSP-friendly: external file, no inline
 * handlers. Bootstrap classes are reused (already on the page via the
 * site package) so no extra CSS is needed.
 */
(function () {
    'use strict';

    function init(form) {
        const button = form.querySelector('[data-ws-meilisearch-rag-submit]')
            || form.querySelector('button[type="submit"]');
        if (!button) {
            return;
        }
        const originalHtml = button.innerHTML;
        // Translatable status text is read from a data-attribute on the
        // button so the partial owns the wording (incl. i18n via
        // <f:translate>) — the JS stays language-agnostic.
        const loadingText = button.dataset.loadingText || 'Antwort wird generiert…';

        function showSpinner() {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'
                + '<span>' + escapeText(loadingText) + '</span>';
        }

        function reset() {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.innerHTML = originalHtml;
        }

        form.addEventListener('submit', function () {
            // The form is a real submit — no preventDefault. Browsers
            // dispatch `submit` before navigation begins, so swapping
            // the button content here lands before the new page paints.
            showSpinner();
        });

        // Restore on bfcache restore — the page comes back with the
        // button still in spinner state otherwise.
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted) {
                reset();
            }
        });
    }

    function escapeText(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }

    function wire() {
        document.querySelectorAll('[data-ws-meilisearch-rag-form]').forEach(init);
    }
})();

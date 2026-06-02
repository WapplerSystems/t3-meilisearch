/**
 * Frontend search Ajax + URL state.
 *
 * Progressive enhancement on top of the standard GET form. Without this
 * file the page still works — every action just does a full reload. With
 * it loaded, the result region refreshes via fetch and the browser URL
 * tracks current state via History API.
 *
 * Hijacked actions: form submit, facet checkbox change, sort dropdown,
 * hybrid toggle, pagination link click. Each builds the next URL from the
 * form + current filter state, pushes it onto history, and fetches a
 * fragment-only render of the same URL to drop into the result region.
 *
 * Why pushState (not hash):
 *   - direct visits, bookmarks and search engines see the real URL with
 *     the actual query params — server still renders the full page
 *   - back / forward replays via popstate
 *
 * Why event delegation:
 *   - we replace the region's innerHTML on every refresh; freshly-
 *     rendered facets / pagination links would lose their listeners
 *     otherwise.
 */
(function () {
    'use strict';

    const REGION_SELECTOR = '[data-ws-meilisearch-region]';
    const FORM_SELECTOR = '.ws-meilisearch-form';
    const QUERY_INPUT_SELECTOR = 'input[name="tx_wsmeilisearch_search[q]"]';
    const FACET_SELECTOR = 'input[name^="tx_wsmeilisearch_search[filters]"]';
    const SORT_SELECTOR = '[data-ws-meilisearch-sort]';
    const HYBRID_SELECTOR = '[data-ws-meilisearch-hybrid]';
    const PAGE_LINK_SELECTOR = '[data-ws-meilisearch-page]';
    const FRAGMENT_ENDPOINT = '/_ws_meilisearch/search-fragment';
    const EXTBASE_PREFIX = 'tx_wsmeilisearch_search[';

    const form = document.querySelector(FORM_SELECTOR);
    const region = document.querySelector(REGION_SELECTOR);

    if (!form || !region || typeof window.fetch !== 'function' || typeof window.history?.pushState !== 'function') {
        return; // No-JS fallback: form already works on its own.
    }

    /**
     * Strip the inline `onchange="this.form.requestSubmit()"` the Facets
     * partial renders for the no-JS fallback. Otherwise it fires alongside
     * our delegated change listener, the implicit submit reaches the form's
     * submit handler, and the submit handler erases the filters (it treats
     * any submit as a "fresh search"). Net result: filter sticks server-
     * side but vanishes from the address bar.
     *
     * Done both on initial load and again after every fragment refresh,
     * because innerHTML replacement re-creates the checkboxes from scratch.
     */
    function deactivateLegacyAutoSubmit() {
        region.querySelectorAll(FACET_SELECTOR).forEach((el) => {
            el.removeAttribute('onchange');
        });
    }
    deactivateLegacyAutoSubmit();

    /**
     * Collect the current submit-target URL from the form + the explicit
     * overrides supplied for the action being performed (e.g. a new page
     * number from a pagination click). The result is a URL with the same
     * tx_wsmeilisearch_search[*] keys the server already parses — this is
     * the URL the address bar will display.
     */
    function buildUrl(overrides = {}) {
        const url = new URL(form.action, window.location.origin);
        const data = new FormData(form);
        for (const [key, value] of data.entries()) {
            url.searchParams.append(key, value);
        }
        // Currently checked facets carry their own input names, so FormData
        // already includes them. Same for the q field. We only have to
        // overlay explicit changes (page, sort, hybrid, q reset, etc.).
        for (const [key, value] of Object.entries(overrides)) {
            // Drop any existing values for this key first, then re-set.
            url.searchParams.delete(key);
            if (Array.isArray(value)) {
                value.forEach((v) => url.searchParams.append(key, v));
            } else if (value !== null && value !== undefined && value !== '') {
                url.searchParams.set(key, String(value));
            }
        }
        return url;
    }

    /**
     * Map the user-facing Extbase URL (?tx_wsmeilisearch_search[q]=…) to
     * the fragment endpoint's compact param shape (?q=…&filters[type][]=…).
     * The endpoint deliberately does not require the Extbase prefix so it
     * can be called directly by anything (curl, server-side template
     * snippets, etc.).
     */
    function fragmentUrlFor(targetUrl) {
        const out = new URL(FRAGMENT_ENDPOINT, window.location.origin);
        for (const [rawKey, value] of targetUrl.searchParams.entries()) {
            if (!rawKey.startsWith(EXTBASE_PREFIX)) continue;
            // "tx_wsmeilisearch_search[a][b][c]" → "a[b][c]"
            const innerKey = rawKey.replace(/^tx_wsmeilisearch_search\[([^\]]+)\]/, '$1');
            // Skip Extbase routing params the endpoint does not need.
            if (innerKey === 'action' || innerKey === 'controller') continue;
            out.searchParams.append(innerKey, value);
        }
        return out;
    }

    /**
     * Refresh the result region from `targetUrl`. The visible address bar
     * gets the user-facing Extbase URL (so direct visits/bookmarks still
     * render the full page); the fetch hits the compact fragment endpoint.
     */
    async function refresh(targetUrl, { pushHistory = true } = {}) {
        const displayUrl = new URL(targetUrl.toString());
        const fetchUrl = fragmentUrlFor(targetUrl);

        region.setAttribute('aria-busy', 'true');
        region.classList.add('ws-meilisearch-result-region--loading');

        try {
            const response = await fetch(fetchUrl.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            const html = await response.text();
            region.innerHTML = html;

            if (pushHistory) {
                window.history.pushState({ wsMeilisearch: true }, '', displayUrl.toString());
            }
            // The fresh DOM contains a new set of facet checkboxes with the
            // inline auto-submit attribute the server emits. Strip it again.
            deactivateLegacyAutoSubmit();
            region.dispatchEvent(new CustomEvent('ws-meilisearch:updated', { bubbles: true }));
        } catch (err) {
            // Hard fallback: navigate to the URL so the user gets a real
            // page render with whatever error TYPO3 produces — better than
            // a stuck spinner.
            window.location.assign(displayUrl.toString());
        } finally {
            region.setAttribute('aria-busy', 'false');
            region.classList.remove('ws-meilisearch-result-region--loading');
        }
    }

    // ---- Hijack: form submit (free-text search) ---------------------------
    form.addEventListener('submit', (event) => {
        // Submit clears any active filters by intent (new search resets
        // the result set) but keeps current sort/hybrid as user prefs.
        event.preventDefault();
        const url = buildUrl({
            'tx_wsmeilisearch_search[page]': 1,
        });
        // Remove all filter[] params — fresh search shouldn't carry old facets.
        for (const key of [...url.searchParams.keys()]) {
            if (key.startsWith('tx_wsmeilisearch_search[filters]')) {
                url.searchParams.delete(key);
            }
        }
        refresh(url);
    });

    // ---- Hijack: facet checkboxes via delegation on the region -----------
    region.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.matches(FACET_SELECTOR)) {
            event.preventDefault();
            // Reset to page 1 — old offset is meaningless under a new filter set.
            const url = buildUrl({ 'tx_wsmeilisearch_search[page]': 1 });
            refresh(url);
            return;
        }

        if (target.matches(SORT_SELECTOR)) {
            event.preventDefault();
            const url = buildUrl({
                'tx_wsmeilisearch_search[sort]': target.value || '',
                'tx_wsmeilisearch_search[page]': 1,
            });
            refresh(url);
            return;
        }

        if (target.matches(HYBRID_SELECTOR)) {
            event.preventDefault();
            const url = buildUrl({
                'tx_wsmeilisearch_search[hybrid]': target.checked ? 1 : 0,
                'tx_wsmeilisearch_search[page]': 1,
            });
            refresh(url);
        }
    });

    // ---- Hijack: pagination link clicks via delegation -------------------
    region.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest(PAGE_LINK_SELECTOR) : null;
        if (!link) return;
        if (link.closest('.disabled, .active')) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        const target = parseInt(link.getAttribute('data-ws-meilisearch-page'), 10);
        if (!Number.isFinite(target) || target <= 0) return;
        const url = buildUrl({ 'tx_wsmeilisearch_search[page]': target });
        refresh(url).then(() => {
            // Pagination = user wants to read different hits — scroll the
            // result region into view but don't jump past the form.
            region.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // ---- Browser back / forward ------------------------------------------
    window.addEventListener('popstate', () => {
        // Re-render against whatever URL the browser is now showing,
        // without pushing another history entry on top.
        const here = new URL(window.location.href);
        refresh(here, { pushHistory: false });
    });
})();

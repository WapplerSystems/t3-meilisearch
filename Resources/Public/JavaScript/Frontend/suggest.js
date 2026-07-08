/**
 * Live autocomplete dropdown for the ws_meilisearch search form.
 *
 * Markup contract:
 *
 *   <div class="ws-meilisearch-suggest" data-ws-meilisearch-suggest>
 *     <input … data-suggest-input />
 *     <ul class="ws-meilisearch-suggest__menu" data-suggest-menu hidden></ul>
 *   </div>
 *
 * The script attaches to every wrapper carrying the
 * `data-ws-meilisearch-suggest` attribute, debounces input events at
 * 150ms, and renders up to five hits as keyboard-navigable list items.
 *
 * Endpoint URL can be overridden on the wrapper via `data-endpoint`;
 * defaults to /_ws_meilisearch/suggest. Result page URL (the "see all
 * N matches" link) is taken from the form's `action` attribute, with
 * the live query swapped into the q parameter.
 */
(function () {
    'use strict';

    const DEBOUNCE_MS = 150;
    const MIN_QUERY_LENGTH = 2;
    const DEFAULT_ENDPOINT = '/_ws_meilisearch/suggest';
    const RECENT_KEY = 'ws-meilisearch:recent';
    const RECENT_MAX = 5;

    /**
     * Read the last-N submitted queries from localStorage. Returns []
     * on parse errors, missing storage (incognito Safari), or empty
     * string entries.
     */
    function loadRecent() {
        try {
            const raw = window.localStorage.getItem(RECENT_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed.filter(function (q) { return typeof q === 'string' && q.trim() !== ''; }).slice(0, RECENT_MAX);
        } catch (_) {
            return [];
        }
    }

    /**
     * Prepend a query, dedupe (case-insensitive), cap at RECENT_MAX.
     * Silently no-ops if localStorage is blocked.
     */
    function saveRecent(query) {
        const q = (query || '').trim();
        if (q === '') return;
        try {
            const list = loadRecent().filter(function (other) {
                return other.toLowerCase() !== q.toLowerCase();
            });
            list.unshift(q);
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
        } catch (_) { /* ignore */ }
    }

    function init(root) {
        const input = root.querySelector('[data-suggest-input]');
        const menu = root.querySelector('[data-suggest-menu]');
        if (!(input instanceof HTMLInputElement) || !(menu instanceof HTMLElement)) return;

        const endpoint = root.dataset.endpoint || DEFAULT_ENDPOINT;
        // Page-subtree scope (KB-style search): mirror the search box's
        // restriction so typeahead suggestions stay inside the same subtree.
        const scope = root.getAttribute('data-ws-meilisearch-scope') || '0';
        // Labels arrive pre-translated from the Fluid template via
        // data-label-* attributes on the wrapper. English fallbacks
        // keep the dropdown usable if the template forgets to wire one.
        const labels = {
            recent: root.dataset.labelRecent || 'Recent searches',
            // 'more' uses %d as the placeholder for the count.
            more: root.dataset.labelMore || '%d more — submit to see all',
        };
        const form = input.closest('form');
        let timer = null;
        let pending = null;
        let activeIndex = -1;
        let items = [];

        function close() {
            menu.hidden = true;
            menu.innerHTML = '';
            activeIndex = -1;
            items = [];
        }

        function open(payload, query) {
            const grouped = payload.grouped === true && Array.isArray(payload.groups);
            const hits = Array.isArray(payload.hits) ? payload.hits : [];
            if (hits.length === 0) {
                close();
                return;
            }
            menu.innerHTML = grouped
                ? renderMenuGrouped(payload.groups, payload.totalHits, query, labels)
                : renderMenu(hits, payload.totalHits, query, labels);
            menu.hidden = false;
            // Image hits don't link to the file: their link points to the
            // results page with q=<searchToken>, which narrows down to this
            // one image (shown there with a preview). Resolve the href here
            // because the results base URL comes from this form.
            menu.querySelectorAll('a[data-search-token]').forEach(function (a) {
                a.setAttribute('href', resultsUrlFor(a.getAttribute('data-search-token')));
            });
            // Keyboard navigation walks the [data-suggest-item] rows;
            // group headers carry no such marker, so they're skipped.
            items = Array.from(menu.querySelectorAll('[data-suggest-item]'));
            activeIndex = -1;
        }

        function resultsUrlFor(token) {
            const base = (form && form.getAttribute('action')) || window.location.pathname;
            const url = new URL(base, window.location.origin);
            // Submit the token under the same field name the search form uses.
            url.searchParams.set(input.getAttribute('name') || 'q', token);
            return url.pathname + url.search;
        }

        function openRecent() {
            const recent = loadRecent();
            if (recent.length === 0) return false;
            menu.innerHTML = renderRecent(recent, labels);
            menu.hidden = false;
            items = Array.from(menu.querySelectorAll('[data-suggest-item]'));
            activeIndex = -1;
            return true;
        }

        function highlight(index) {
            items.forEach(function (li, i) { li.classList.toggle('active', i === index); });
            const active = items[index];
            if (active) {
                input.setAttribute('aria-activedescendant', active.id || '');
                active.scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function fire() {
            const query = input.value.trim();
            if (query.length < MIN_QUERY_LENGTH) {
                // Sub-threshold input: show the recent-queries history
                // instead of going dark. If the user has none, fall
                // through to the close()-default below.
                if (query === '' && openRecent()) return;
                close();
                return;
            }
            if (pending) pending.abort();
            const ctrl = new AbortController();
            pending = ctrl;
            fetch(endpoint + '?q=' + encodeURIComponent(query) + (scope && scope !== '0' ? '&scope=' + encodeURIComponent(scope) : ''), {
                signal: ctrl.signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            })
                .then(function (r) { return r.ok ? r.json() : { hits: [], totalHits: 0 }; })
                .then(function (payload) {
                    if (ctrl.signal.aborted) return;
                    open(payload, query);
                })
                .catch(function () { /* network blip; leave the menu untouched */ });
        }

        input.addEventListener('input', function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(fire, DEBOUNCE_MS);
        });

        input.addEventListener('keydown', function (ev) {
            if (menu.hidden || items.length === 0) {
                if (ev.key === 'ArrowDown') { fire(); ev.preventDefault(); }
                return;
            }
            switch (ev.key) {
                case 'ArrowDown':
                    activeIndex = (activeIndex + 1) % items.length;
                    highlight(activeIndex);
                    ev.preventDefault();
                    break;
                case 'ArrowUp':
                    activeIndex = (activeIndex - 1 + items.length) % items.length;
                    highlight(activeIndex);
                    ev.preventDefault();
                    break;
                case 'Enter':
                    if (activeIndex >= 0) {
                        const link = items[activeIndex].querySelector('a');
                        if (link) {
                            window.location.href = link.href;
                            ev.preventDefault();
                        }
                    }
                    break;
                case 'Escape':
                    close();
                    break;
            }
        });

        input.addEventListener('blur', function () {
            // Delay so a click on a menu item registers before the menu
            // closes (Safari fires blur before click on the item).
            setTimeout(close, 150);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= MIN_QUERY_LENGTH) {
                fire();
            } else {
                // Empty / short input on focus → reveal the recent list
                // immediately (no debounce wait — it's local data).
                openRecent();
            }
        });

        // Persist the query on form submit so the next page load has a
        // history to show. Click on a suggestion also triggers this via
        // the synthetic submit further down.
        if (form) {
            form.addEventListener('submit', function () {
                saveRecent(input.value);
                // Confirming the search (Enter or the submit button) shows the
                // result list — the suggestion dropdown must close, just like
                // it does on a button click.
                close();
            });
        }

        // Click on a recent-queries entry: fill the input and submit
        // the form so the existing GET pipeline runs. We listen on
        // `mousedown` (not `click`) because the input's blur handler
        // closes the menu on mousedown — `click` would never fire.
        menu.addEventListener('mousedown', function (ev) {
            const recentItem = ev.target.closest('[data-recent-query]');
            if (!recentItem) return;
            ev.preventDefault();
            input.value = recentItem.dataset.recentQuery || '';
            if (form) {
                saveRecent(input.value);
                form.submit();
            }
        });

        // Optional: clicking outside the wrapper closes the menu too.
        document.addEventListener('click', function (ev) {
            if (!root.contains(ev.target)) close();
        });

        // If we landed on this page with the input already filled in
        // (Results.html pre-populates from the GET query), that's a
        // completed search the user might want to return to — record
        // it in recents right away rather than waiting for them to
        // submit something new.
        if (input.value.trim() !== '') {
            saveRecent(input.value);
        }

        // ARIA wiring for the combobox / listbox pair.
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        const menuId = menu.id || ('ws-meilisearch-suggest-' + Math.random().toString(36).slice(2));
        menu.id = menuId;
        menu.setAttribute('role', 'listbox');
        input.setAttribute('aria-controls', menuId);
        // Update aria-expanded when the menu toggles.
        const observer = new MutationObserver(function () {
            input.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
        });
        observer.observe(menu, { attributes: true, attributeFilter: ['hidden'] });
    }

    function renderRecent(queries, labels) {
        const header = '<li class="ws-meilisearch-suggest__header text-muted small text-uppercase">' + escapeText(labels.recent) + '</li>';
        const items = queries.map(function (q, i) {
            return [
                '<li role="option" id="ws-meilisearch-suggest-item-' + i + '"',
                ' data-suggest-item class="ws-meilisearch-suggest__item">',
                '<a href="#" data-recent-query="' + escapeAttr(q) + '" class="d-flex align-items-center gap-2 text-decoration-none text-reset">',
                '<span class="ws-meilisearch-suggest__icon" aria-hidden="true">↻</span>',
                '<span class="flex-grow-1 text-truncate">' + escapeText(q) + '</span>',
                '</a>',
                '</li>',
            ].join('');
        }).join('');
        return header + items;
    }

    // One dropdown row. showBadge=false in grouped mode, where the
    // section header already names the type so a per-row badge would be
    // redundant.
    function renderHitItem(hit, i, showBadge) {
        // Image hits: link to the results page (q=<searchToken>) instead of
        // the file. href is finalised in open() via data-search-token, since
        // the results base URL depends on the form.
        var href, tokenAttr;
        if (hit.searchToken) {
            href = '#';
            tokenAttr = ' data-search-token="' + escapeAttr(hit.searchToken) + '"';
        } else {
            href = escapeAttr(linkFor(hit));
            tokenAttr = '';
        }
        return [
            '<li role="option" id="ws-meilisearch-suggest-item-' + i + '"',
            ' data-suggest-item class="ws-meilisearch-suggest__item">',
            '<a href="' + href + '"' + tokenAttr + ' class="d-flex align-items-center gap-2 text-decoration-none text-reset">',
            showBadge ? '<span class="' + badgeClass(hit.type) + '">' + escapeText(hit.typeLabel || hit.type || '?') + '</span>' : '',
            '<span class="flex-grow-1 text-truncate">' + escapeText(hit.title || hit.id || '') + '</span>',
            '</a>',
            '</li>',
        ].join('');
    }

    // Grouped dropdown: one labelled section per type. Item ids stay
    // globally unique (running idx) so aria-activedescendant works; the
    // header <li> carries no data-suggest-item, so keyboard nav skips it.
    function renderMenuGrouped(groups, totalHits, query, labels) {
        var idx = 0;
        var shown = 0;
        var sections = groups.map(function (group) {
            var head = '<li class="ws-meilisearch-suggest__group-header text-muted small text-uppercase" role="presentation">'
                + escapeText(group.label || group.type || '') + '</li>';
            var rows = (Array.isArray(group.hits) ? group.hits : []).map(function (hit) {
                shown++;
                return renderHitItem(hit, idx++, false);
            }).join('');
            return head + rows;
        }).join('');
        var footer = (totalHits > shown)
            ? '<li class="ws-meilisearch-suggest__footer text-muted small">' + escapeText(labels.more.replace('%d', String(totalHits - shown))) + '</li>'
            : '';
        return sections + footer;
    }

    function renderMenu(hits, totalHits, query, labels) {
        const items = hits.map(function (hit, i) {
            return renderHitItem(hit, i, true);
        }).join('');
        const footer = (totalHits > hits.length)
            ? '<li class="ws-meilisearch-suggest__footer text-muted small">' + escapeText(labels.more.replace('%d', String(totalHits - hits.length))) + '</li>'
            : '';
        return items + footer;
    }

    function linkFor(hit) {
        // The suggest endpoint already resolves the right target for
        // every type into publicUrl (file public URL for files, the
        // speaking page/record URI for pages/news/knowledge resources).
        // Use it directly instead of rebuilding a non-speaking ?id= link
        // (which ignored the language prefix) or returning '#'.
        var url = hit.publicUrl;
        if (url) {
            // Absolute URLs (e.g. S3-hosted files) pass through; a
            // storage-relative file path without a leading slash gets one.
            if (/^https?:\/\//i.test(url) || url.charAt(0) === '/') {
                return url;
            }
            return '/' + url.replace(/^\/+/, '');
        }
        // Fallback only when no URL was resolved (e.g. a page without an
        // indexed URI): a non-speaking id link still beats a dead '#'.
        if (hit.type === 'page' && hit.uid) {
            return '?id=' + encodeURIComponent(hit.uid);
        }
        return '#';
    }

    function badgeClass(type) {
        switch (type) {
            case 'page': return 'badge text-bg-primary';
            case 'news': return 'badge text-bg-info';
            case 'file': return 'badge text-bg-warning';
            default:     return 'badge text-bg-secondary';
        }
    }

    function escapeText(s) {
        return String(s).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }

    function escapeAttr(s) {
        return escapeText(s).replace(/"/g, '&quot;');
    }

    /**
     * Auto-attach mode: wrap existing search-form inputs that match a
     * configured CSS selector so sites without the ws_meilisearch_search
     * Fluid template (e.g. the legacy indexed_search form, a hand-rolled
     * header search) still get the live dropdown. The selector is read
     * from a <meta name="ws-meilisearch-suggest"> tag set up by the
     * setup TypoScript when meilisearch.suggest.autoAttachInputSelector
     * is configured.
     *
     * Wrap shape mirrors the explicit markup contract so the existing
     * init(root) path can attach unchanged: a wrapper carrying
     * data-ws-meilisearch-suggest, the input tagged with
     * data-suggest-input, plus an injected sibling <ul data-suggest-menu>
     * positioned right under the input via the same CSS that ships with
     * the Fluid version. Idempotent — re-running attachAuto() doesn't
     * double-wrap (the input already has data-suggest-input set).
     */
    function attachAuto() {
        const meta = document.querySelector('meta[name="ws-meilisearch-suggest"]');
        if (!meta) return;
        const selector = meta.getAttribute('data-input-selector') || '';
        if (selector === '') return;
        const endpoint = meta.getAttribute('data-endpoint') || DEFAULT_ENDPOINT;
        const labelRecent = meta.getAttribute('data-label-recent') || '';
        const labelMore = meta.getAttribute('data-label-more') || '';
        document.querySelectorAll(selector).forEach(function (input) {
            if (!(input instanceof HTMLInputElement)) return;
            if (input.hasAttribute('data-suggest-input')) return; // already wired
            // Promote the input's parent (or the input itself wrapped in
            // a fresh <span>) to the suggest wrapper. Prefer the closest
            // form-control container so absolutely-positioned menus stay
            // aligned with the input's box.
            let wrapper = input.closest('[data-ws-meilisearch-suggest]');
            if (!wrapper) {
                wrapper = document.createElement('span');
                wrapper.className = 'ws-meilisearch-suggest';
                wrapper.setAttribute('data-ws-meilisearch-suggest', '');
                wrapper.style.position = 'relative';
                wrapper.style.display = 'inline-block';
                wrapper.style.width = '100%';
                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
            }
            wrapper.dataset.endpoint = endpoint;
            if (labelRecent !== '') wrapper.dataset.labelRecent = labelRecent;
            if (labelMore !== '') wrapper.dataset.labelMore = labelMore;
            input.setAttribute('data-suggest-input', '');
            input.setAttribute('autocomplete', 'off');
            // Inject the menu list if it isn't already there. Stays
            // hidden until the first keystroke produces a payload.
            let menu = wrapper.querySelector('[data-suggest-menu]');
            if (!menu) {
                menu = document.createElement('ul');
                menu.className = 'ws-meilisearch-suggest__menu';
                menu.setAttribute('data-suggest-menu', '');
                menu.hidden = true;
                wrapper.appendChild(menu);
            }
            init(wrapper);
        });
    }

    function start() {
        document.querySelectorAll('[data-ws-meilisearch-suggest]').forEach(init);
        attachAuto();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

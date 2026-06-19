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
            const hits = Array.isArray(payload.hits) ? payload.hits : [];
            if (hits.length === 0) {
                close();
                return;
            }
            menu.innerHTML = renderMenu(hits, payload.totalHits, query, labels);
            menu.hidden = false;
            items = Array.from(menu.querySelectorAll('[data-suggest-item]'));
            activeIndex = -1;
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
            fetch(endpoint + '?q=' + encodeURIComponent(query), {
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

    function renderMenu(hits, totalHits, query, labels) {
        const items = hits.map(function (hit, i) {
            return [
                '<li role="option" id="ws-meilisearch-suggest-item-' + i + '"',
                ' data-suggest-item class="ws-meilisearch-suggest__item">',
                '<a href="' + escapeAttr(linkFor(hit)) + '" class="d-flex align-items-center gap-2 text-decoration-none text-reset">',
                '<span class="' + badgeClass(hit.type) + '">' + escapeText(hit.typeLabel || hit.type || '?') + '</span>',
                '<span class="flex-grow-1 text-truncate">' + escapeText(hit.title || hit.id || '') + '</span>',
                '</a>',
                '</li>',
            ].join('');
        }).join('');
        const footer = (totalHits > hits.length)
            ? '<li class="ws-meilisearch-suggest__footer text-muted small">' + escapeText(labels.more.replace('%d', String(totalHits - hits.length))) + '</li>'
            : '';
        return items + footer;
    }

    function linkFor(hit) {
        if (hit.type === 'page' && hit.uid) {
            return '?id=' + encodeURIComponent(hit.uid);
        }
        if (hit.type === 'file' && hit.publicUrl) {
            return '/' + hit.publicUrl.replace(/^\/+/, '');
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

    function start() {
        document.querySelectorAll('[data-ws-meilisearch-suggest]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

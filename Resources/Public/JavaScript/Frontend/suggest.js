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

    function init(root) {
        const input = root.querySelector('[data-suggest-input]');
        const menu = root.querySelector('[data-suggest-menu]');
        if (!(input instanceof HTMLInputElement) || !(menu instanceof HTMLElement)) return;

        const endpoint = root.dataset.endpoint || DEFAULT_ENDPOINT;
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
            menu.innerHTML = renderMenu(hits, payload.totalHits, query);
            menu.hidden = false;
            items = Array.from(menu.querySelectorAll('[data-suggest-item]'));
            activeIndex = -1;
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
            if (input.value.trim().length >= MIN_QUERY_LENGTH) fire();
        });

        // Optional: clicking outside the wrapper closes the menu too.
        document.addEventListener('click', function (ev) {
            if (!root.contains(ev.target)) close();
        });

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

    function renderMenu(hits, totalHits, query) {
        const items = hits.map(function (hit, i) {
            return [
                '<li role="option" id="ws-meilisearch-suggest-item-' + i + '"',
                ' data-suggest-item class="ws-meilisearch-suggest__item">',
                '<a href="' + escapeAttr(linkFor(hit)) + '" class="d-flex align-items-center gap-2 text-decoration-none text-reset">',
                '<span class="' + badgeClass(hit.type) + '">' + escapeText(hit.type || '?') + '</span>',
                '<span class="flex-grow-1 text-truncate">' + escapeText(hit.title || hit.id || '') + '</span>',
                '</a>',
                '</li>',
            ].join('');
        }).join('');
        const footer = (totalHits > hits.length)
            ? '<li class="ws-meilisearch-suggest__footer text-muted small">+ ' + (totalHits - hits.length) + ' more — submit to see all</li>'
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

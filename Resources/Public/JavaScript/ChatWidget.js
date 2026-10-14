/*
 * RAG floating chat widget.
 *
 * Markup contract (rendered by ChatWidgetRenderer when
 * meilisearch.rag.chatWidget.enabled = 1):
 *
 *   <div id="ws-meilisearch-chat-widget"
 *        data-url="/de/suche?type=1748231"
 *        data-label="KI-Chat"
 *        data-config="{…json…}"></div>
 *
 * data-config carries the site's branding from the chatWidget.* site
 * settings, already translated: title, subtitle, launcherText, icon, teaser,
 * expandable, greeting (title, text, hint, groups of example questions),
 * disclaimer and the button labels. Everything visual beyond that is a CSS
 * custom property (--wsms-chat-*, ChatWidget.css), so a site brands the
 * widget without touching this file.
 *
 * This script upgrades the anchor into:
 *   - a fixed launcher button (bottom-right), icon-only or with a text
 *   - an optional teaser bubble above it, once per browser session
 *   - a panel (role=dialog) with head (icon, title, subtitle, new
 *     conversation, expand, close) whose chat shell is fetched from data-url
 *     (the bare RAG embed type) and rendered INLINE in the host DOM — no
 *     iframe — then driven by RagStream.js over the SSE endpoint
 *   - a greeting with clickable example questions in the empty panel, shown
 *     again after "new conversation"
 *
 * Escape closes the panel; focus goes to the input on open and back to the
 * launcher on close. The fetch is lazy — it runs the first time the panel
 * opens, so the launcher itself adds no network cost.
 *
 * No framework, no module imports. Drop-in <script defer> via
 * page.includeJSFooter so the DOM is ready by the time we run.
 */
(function () {
    'use strict';

    const ROOT_ID = 'ws-meilisearch-chat-widget';
    // sessionStorage keys: no cookie, gone with the tab session.
    const KEY_TEASER = 'wsms-chat-teaser';
    const KEY_OPENED = 'wsms-chat-opened';
    const KEY_EXPANDED = 'wsms-chat-expanded';
    // Below this width the panel is full screen: no expand, no teaser.
    const DESKTOP = '(min-width: 768px)';

    const ICONS = {
        chat: '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        newChat: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        expand: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
        shrink: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
        close: '<path d="M6 6l12 12M18 6 6 18"/>',
        send: '<path d="M5 12h14M13 6l6 6-6 6"/>'
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    function svg(name, cls) {
        return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
            + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICONS[name] + '</svg>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // sessionStorage can throw (privacy mode, blocked storage): the widget
    // then simply forgets, it never breaks.
    function sget(key) { try { return window.sessionStorage.getItem(key); } catch (_) { return null; } }
    function sset(key, value) { try { window.sessionStorage.setItem(key, value); } catch (_) { /* ignore */ } }

    function init() {
        // Inside an iframe (e.g. an old embed of the chat page) the widget
        // would sprout its own launcher inside the panel.
        try {
            if (window.self !== window.top) {
                return;
            }
        } catch (_) {
            return;
        }
        const anchor = document.getElementById(ROOT_ID);
        if (!anchor || anchor.dataset.wsmsInit === '1') {
            return;
        }
        anchor.dataset.wsmsInit = '1';

        const targetUrl = (anchor.dataset.url || '').trim();
        if (targetUrl === '') {
            return;
        }
        let cfg = {};
        try { cfg = JSON.parse(anchor.dataset.config || '{}') || {}; } catch (_) { cfg = {}; }
        const label = (anchor.dataset.label || 'Chat').trim();
        const labels = Object.assign({
            newChat: 'New conversation', expand: 'Expand', shrink: 'Shrink', close: 'Close chat',
            closeTeaser: 'Close notice', loading: 'Loading …', error: 'The chat could not be loaded.',
            send: 'Send question'
        }, cfg.labels || {});
        const title = (cfg.title || label).toString();
        const desktop = window.matchMedia ? window.matchMedia(DESKTOP) : { matches: true };

        const iconHtml = function (cls) {
            return cfg.icon
                ? '<img class="' + cls + '" src="' + escapeHtml(cfg.icon) + '" alt="" aria-hidden="true">'
                : svg('chat', cls);
        };

        // --- Build DOM ---------------------------------------------------

        // One scope element: the custom properties of a site theme apply to
        // launcher, teaser and panel alike.
        const scope = document.createElement('div');
        scope.className = 'ws-meilisearch-chat';

        const launcher = document.createElement('button');
        launcher.type = 'button';
        launcher.className = 'ws-meilisearch-chat-launcher' + (cfg.launcherText ? '' : ' ws-meilisearch-chat-launcher--icon-only');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.setAttribute('aria-controls', 'ws-meilisearch-chat-panel');
        launcher.innerHTML = iconHtml('ws-meilisearch-chat-launcher__icon')
            + (cfg.launcherText ? '<span class="ws-meilisearch-chat-launcher__text">' + escapeHtml(cfg.launcherText) + '</span>' : '');
        if (!cfg.launcherText) {
            launcher.setAttribute('aria-label', label);
            launcher.title = label;
        }

        let teaser = null;
        const teaserCfg = cfg.teaser || {};
        if (teaserCfg.text) {
            teaser = document.createElement('div');
            teaser.className = 'ws-meilisearch-chat-teaser';
            teaser.hidden = true;
            teaser.innerHTML = '<button type="button" class="ws-meilisearch-chat-teaser__open">'
                + (teaserCfg.title ? '<strong class="ws-meilisearch-chat-teaser__title">' + escapeHtml(teaserCfg.title) + '</strong>' : '')
                + '<span class="ws-meilisearch-chat-teaser__text">' + escapeHtml(teaserCfg.text) + '</span>'
                + '</button>'
                + '<button type="button" class="ws-meilisearch-chat-teaser__close" aria-label="' + escapeHtml(labels.closeTeaser) + '">'
                + svg('close') + '</button>';
        }

        const panel = document.createElement('section');
        panel.id = 'ws-meilisearch-chat-panel';
        panel.className = 'ws-meilisearch-chat-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'false');
        panel.setAttribute('aria-label', cfg.subtitle ? title + ' – ' + cfg.subtitle : label);
        panel.hidden = true;
        panel.innerHTML =
            '<div class="ws-meilisearch-chat-panel__header">'
            + iconHtml('ws-meilisearch-chat-panel__icon')
            + '<div class="ws-meilisearch-chat-panel__titles">'
            + '<span class="ws-meilisearch-chat-panel__heading">' + escapeHtml(title) + '</span>'
            + (cfg.subtitle ? '<span class="ws-meilisearch-chat-panel__subtitle">' + escapeHtml(cfg.subtitle) + '</span>' : '')
            + '</div>'
            + '<button type="button" class="ws-meilisearch-chat-panel__btn" data-wsms-action="new" aria-label="' + escapeHtml(labels.newChat) + '" title="' + escapeHtml(labels.newChat) + '">' + svg('newChat') + '</button>'
            + (cfg.expandable ? '<button type="button" class="ws-meilisearch-chat-panel__btn ws-meilisearch-chat-panel__btn--expand" data-wsms-action="expand"></button>' : '')
            + '<button type="button" class="ws-meilisearch-chat-panel__btn ws-meilisearch-chat-panel__close" data-wsms-action="close" aria-label="' + escapeHtml(labels.close) + '" title="' + escapeHtml(labels.close) + '">' + svg('close') + '</button>'
            + '</div>';

        // Chat content is rendered inline in the host DOM (no iframe): on first
        // open we fetch the bare RAG shell (the chatWidget embed type) and inject
        // it here, then hand it to RagStream.js. The .ws-meilisearch-rag-embed
        // class keeps RagEmbed.css (scoped) in control of the look even on a
        // Bootstrap host page.
        const body = document.createElement('div');
        body.className = 'ws-meilisearch-chat-panel__body ws-meilisearch-rag-embed';
        panel.appendChild(body);

        if (teaser) { scope.appendChild(teaser); }
        scope.appendChild(launcher);
        scope.appendChild(panel);
        document.body.appendChild(scope);

        const expandBtn = panel.querySelector('[data-wsms-action="expand"]');

        // --- Expanded side panel -----------------------------------------

        function setExpanded(on) {
            panel.classList.toggle('is-expanded', on);
            if (!expandBtn) return;
            const text = on ? labels.shrink : labels.expand;
            expandBtn.setAttribute('aria-label', text);
            expandBtn.title = text;
            expandBtn.innerHTML = svg(on ? 'shrink' : 'expand');
            expandBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        setExpanded(!!cfg.expandable && sget(KEY_EXPANDED) === '1');

        // --- Greeting with example questions -----------------------------

        let shell = null;
        let thread = null;
        let greeting = null;

        function buildGreeting() {
            const g = cfg.greeting || {};
            const groups = Array.isArray(g.groups) ? g.groups.filter(function (grp) {
                return grp && Array.isArray(grp.questions) && grp.questions.length;
            }) : [];
            if (!g.title && !g.text && !groups.length) {
                return null;
            }
            const el = document.createElement('div');
            el.className = 'ws-meilisearch-chat-greeting';
            let html = '';
            if (g.title) { html += '<h2 class="ws-meilisearch-chat-greeting__title">' + escapeHtml(g.title) + '</h2>'; }
            if (g.text) { html += '<p class="ws-meilisearch-chat-greeting__text">' + escapeHtml(g.text) + '</p>'; }
            if (groups.length && g.hint) { html += '<p class="ws-meilisearch-chat-greeting__hint">' + escapeHtml(g.hint) + '</p>'; }
            groups.forEach(function (grp) {
                const key = String(grp.key || '').replace(/[^a-z0-9_-]/gi, '');
                html += '<div class="ws-meilisearch-chat-examples ws-meilisearch-chat-examples--' + key + '">';
                if (grp.label) { html += '<p class="ws-meilisearch-chat-examples__label">' + escapeHtml(grp.label) + '</p>'; }
                grp.questions.forEach(function (q) {
                    html += '<button type="button" class="ws-meilisearch-chat-example ws-meilisearch-chat-example--' + key + '"'
                        + ' data-wsms-example="' + escapeHtml(q) + '">' + escapeHtml(q) + '</button>';
                });
                html += '</div>';
            });
            el.innerHTML = html;
            el.addEventListener('click', function (e) {
                const btn = e.target.closest('[data-wsms-example]');
                if (!btn || !shell) return;
                shell.dispatchEvent(new CustomEvent('wsms:rag-ask', { detail: { question: btn.getAttribute('data-wsms-example') } }));
            });
            return el;
        }

        // The greeting stands for the empty conversation: it disappears with
        // the first turn — streamed or restored from the session — and comes
        // back when the thread is cleared.
        function syncGreeting() {
            if (greeting && thread) {
                greeting.hidden = thread.children.length > 0;
            }
        }

        function buildDisclaimer() {
            const d = cfg.disclaimer;
            if (!d || !(d.before || d.linkText)) return null;
            const el = document.createElement('p');
            el.className = 'ws-meilisearch-chat-disclaimer';
            el.appendChild(document.createTextNode(d.before || ''));
            if (d.linkText && d.href) {
                const a = document.createElement('a');
                a.href = d.href;
                a.textContent = d.linkText;
                el.appendChild(a);
            }
            el.appendChild(document.createTextNode(d.after || ''));
            return el;
        }

        // The panel's own input: the site's placeholder, and an icon button
        // instead of the worded one of the plugin page. Runs before
        // RagStream.js wires the form, which remembers the button content to
        // restore it after an answer.
        function dressForm(root) {
            const input = root.querySelector('input[name="tx_wsmeilisearch_rag[q]"], input[name="q"]');
            if (input && cfg.placeholder) {
                input.placeholder = cfg.placeholder;
                const lbl = input.id ? root.querySelector('label[for="' + input.id + '"]') : null;
                if (lbl) lbl.textContent = cfg.placeholder;
            }
            const submit = root.querySelector('[data-ws-meilisearch-rag-submit], button[type="submit"]');
            if (submit) {
                submit.classList.add('ws-meilisearch-chat-send');
                submit.setAttribute('aria-label', labels.send);
                submit.title = labels.send;
                submit.innerHTML = svg('send');
            }
        }

        // --- Loading the shell -------------------------------------------

        let opened = false;
        let loaded = false;

        function focusInput() {
            const input = body.querySelector('input[name="tx_wsmeilisearch_rag[q]"], input[name="q"]');
            if (input) {
                input.focus({ preventScroll: true });
                return true;
            }
            return false;
        }

        // First open: fetch the bare RAG shell (chatWidget embed type) and inject
        // it inline, then let RagStream.js wire it. Lazy, same-origin, sends the
        // fe_typo_user cookie so the server can seed conversation history.
        function loadChat() {
            if (loaded) {
                return;
            }
            loaded = true;
            body.innerHTML = '<div class="ws-meilisearch-chat-panel__status" role="status">' + escapeHtml(labels.loading) + '</div>';
            fetch(targetUrl, { credentials: 'include', headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error(String(r.status))); })
                .then(function (html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const found = doc.querySelector('[data-ws-meilisearch-rag-stream]');
                    if (!found) {
                        throw new Error('chat shell not found in response');
                    }
                    body.innerHTML = '';
                    shell = document.importNode(found, true);
                    body.appendChild(shell);
                    thread = shell.querySelector('[data-rag-thread]');
                    if (thread) {
                        // New answers are announced, politely.
                        thread.setAttribute('aria-live', 'polite');
                        greeting = buildGreeting();
                        if (greeting) {
                            thread.parentNode.insertBefore(greeting, thread);
                            new MutationObserver(syncGreeting).observe(thread, { childList: true });
                            syncGreeting();
                        }
                    }
                    dressForm(shell);
                    const disclaimer = buildDisclaimer();
                    const ask = shell.querySelector('.ws-meilisearch-rag-ask');
                    if (disclaimer && ask) {
                        ask.appendChild(disclaimer);
                    }
                    if (typeof window.wsMeilisearchRagStreamInit === 'function') {
                        window.wsMeilisearchRagStreamInit(body);
                    }
                    if (opened) {
                        focusInput();
                    }
                })
                .catch(function () {
                    loaded = false; // let the next open retry
                    body.innerHTML = '<div class="ws-meilisearch-chat-panel__status ws-meilisearch-chat-panel__status--error" role="alert">'
                        + escapeHtml(labels.error) + '</div>';
                });
        }

        // --- Open / close ------------------------------------------------

        function hideTeaser() {
            if (teaser) teaser.hidden = true;
        }

        function open() {
            if (opened) {
                return;
            }
            opened = true;
            sset(KEY_OPENED, '1');
            hideTeaser();
            loadChat();
            panel.hidden = false;
            // Force a reflow so the CSS transition kicks in instead of
            // jumping from display:none → opacity:1 in one paint.
            // eslint-disable-next-line no-unused-expressions
            panel.offsetHeight;
            panel.classList.add('is-open');
            launcher.classList.add('is-active');
            launcher.setAttribute('aria-expanded', 'true');
            // Keyboard users land in the input; before the shell has loaded,
            // on the close button, and loadChat() moves them on.
            if (!focusInput()) {
                panel.querySelector('[data-wsms-action="close"]').focus({ preventScroll: true });
            }
        }

        function close() {
            if (!opened) {
                return;
            }
            opened = false;
            panel.classList.remove('is-open');
            launcher.classList.remove('is-active');
            launcher.setAttribute('aria-expanded', 'false');
            // Wait for the transition to end before hiding so screen readers
            // don't see the panel mid-collapse; a fallback timer covers
            // reduced motion, where no transition (and no event) happens.
            let done = false;
            const finish = function () {
                if (done) return;
                done = true;
                panel.removeEventListener('transitionend', finish);
                if (!opened) {
                    panel.hidden = true;
                }
            };
            panel.addEventListener('transitionend', finish);
            setTimeout(finish, 250);
            launcher.focus({ preventScroll: true });
        }

        launcher.addEventListener('click', function () {
            opened ? close() : open();
        });
        panel.querySelector('.ws-meilisearch-chat-panel__header').addEventListener('click', function (e) {
            const btn = e.target.closest('[data-wsms-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-wsms-action');
            if (action === 'close') {
                close();
            } else if (action === 'expand') {
                const on = !panel.classList.contains('is-expanded');
                setExpanded(on);
                sset(KEY_EXPANDED, on ? '1' : '');
            } else if (action === 'new' && shell) {
                shell.dispatchEvent(new CustomEvent('wsms:rag-reset'));
                syncGreeting();
                body.scrollTop = 0;
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && opened) {
                close();
            }
        });

        // --- Teaser bubble ----------------------------------------------

        if (teaser) {
            teaser.querySelector('.ws-meilisearch-chat-teaser__open').addEventListener('click', open);
            teaser.querySelector('.ws-meilisearch-chat-teaser__close').addEventListener('click', function () {
                hideTeaser();
                launcher.focus({ preventScroll: true });
            });
            // Once per session, desktop only, and never for a visitor who
            // already found the chat.
            if (desktop.matches && !sget(KEY_TEASER) && !sget(KEY_OPENED)) {
                setTimeout(function () {
                    if (opened || !desktop.matches) return;
                    teaser.hidden = false;
                    sset(KEY_TEASER, '1');
                }, Math.max(0, Number(teaserCfg.delay) || 0) * 1000);
            }
        }
    }
}());

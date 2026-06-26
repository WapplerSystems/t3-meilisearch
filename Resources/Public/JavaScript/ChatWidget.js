/*
 * RAG floating chat widget.
 *
 * Markup contract (rendered by setup.typoscript when
 * meilisearch.rag.chatWidget.enabled = 1):
 *
 *   <div id="ws-meilisearch-chat-widget"
 *        data-url="/de/suche"
 *        data-label="KI-Chat öffnen"></div>
 *
 * This script upgrades that anchor div into:
 *   - a fixed bubble button (bottom-right)
 *   - a slide-up panel whose chat shell is fetched from data-url (the bare
 *     RAG embed type) and rendered INLINE in the host DOM — no iframe —
 *     then driven by RagStream.js over the SSE endpoint.
 *
 * Open/close via the bubble, the panel's close button, or Escape.
 * The fetch is lazy — it runs the first time the panel opens, so the bubble
 * itself adds no network cost.
 *
 * No framework, no module imports. Drop-in <script defer> via
 * page.includeJSFooter so the DOM is ready by the time we run.
 */
(function () {
    'use strict';

    const ROOT_ID = 'ws-meilisearch-chat-widget';

    // page.includeJSFooter injects the <script> just before </body>,
    // but TYPO3 renders it BEFORE the footerData markup — so when this
    // file is parsed synchronously, the target <div> doesn't exist yet.
    // Defer initialisation until the parser has reached the anchor.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    function init() {
    // If we are ourselves inside a chat-widget iframe (or any other
    // iframe), skip rendering — otherwise the embedded RAG page would
    // sprout its own bubble inside the panel. The try/catch handles
    // exotic sandbox scenarios where window.top throws.
    try {
        if (window.self !== window.top) {
            return;
        }
    } catch (_) {
        return;
    }
    const root = document.getElementById(ROOT_ID);
    if (!root) {
        return;
    }
    // Idempotency guard — TYPO3's USER_INT chains can occasionally
    // include this asset twice during partial reloads.
    if (root.dataset.wsmsInit === '1') {
        return;
    }
    root.dataset.wsmsInit = '1';

    const targetUrl = (root.dataset.url || '').trim();
    const label = (root.dataset.label || 'KI-Chat').trim();
    const loadingText = (root.dataset.loading || 'Wird geladen …').trim();
    const errorText = (root.dataset.error || 'Der Chat konnte nicht geladen werden.').trim();
    if (targetUrl === '') {
        return;
    }

    const escapeHtml = (s) => String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

    // --- Build DOM ---------------------------------------------------

    const bubble = document.createElement('button');
    bubble.type = 'button';
    bubble.className = 'ws-meilisearch-chat-bubble';
    bubble.setAttribute('aria-label', label);
    bubble.setAttribute('title', label);
    bubble.setAttribute('aria-expanded', 'false');
    bubble.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';

    const panel = document.createElement('div');
    panel.className = 'ws-meilisearch-chat-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', label);
    panel.hidden = true;

    const header = document.createElement('div');
    header.className = 'ws-meilisearch-chat-panel__header';

    const heading = document.createElement('span');
    heading.className = 'ws-meilisearch-chat-panel__heading';
    heading.textContent = label;
    header.appendChild(heading);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'ws-meilisearch-chat-panel__close';
    closeBtn.setAttribute('aria-label', 'Schließen');
    closeBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    header.appendChild(closeBtn);

    panel.appendChild(header);

    // Chat content is rendered inline in the host DOM (no iframe): on first
    // open we fetch the bare RAG shell (the chatWidget embed type) and inject
    // it here, then hand it to RagStream.js. The .ws-meilisearch-rag-embed
    // class keeps RagEmbed.css (scoped) in control of the look even on a
    // Bootstrap host page.
    const body = document.createElement('div');
    body.className = 'ws-meilisearch-chat-panel__body ws-meilisearch-rag-embed';
    panel.appendChild(body);

    document.body.appendChild(bubble);
    document.body.appendChild(panel);

    // --- Open / close ------------------------------------------------

    let opened = false;
    let loaded = false;

    // First open: fetch the bare RAG shell (chatWidget embed type) and inject
    // it inline, then let RagStream.js wire it. Lazy, same-origin, sends the
    // fe_typo_user cookie so the server can seed conversation history.
    const loadChat = () => {
        if (loaded) {
            return;
        }
        loaded = true;
        body.innerHTML = '<div class="ws-meilisearch-chat-panel__status">' + escapeHtml(loadingText) + '</div>';
        fetch(targetUrl, { credentials: 'include', headers: { 'X-Requested-With': 'fetch' } })
            .then((r) => (r.ok ? r.text() : Promise.reject(new Error(String(r.status)))))
            .then((html) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const shell = doc.querySelector('[data-ws-meilisearch-rag-stream]');
                if (!shell) {
                    throw new Error('chat shell not found in response');
                }
                body.innerHTML = '';
                body.appendChild(document.importNode(shell, true));
                if (typeof window.wsMeilisearchRagStreamInit === 'function') {
                    window.wsMeilisearchRagStreamInit(body);
                }
                const input = body.querySelector('input[name="tx_wsmeilisearch_rag[q]"], input[name="q"]');
                if (input) {
                    input.focus({ preventScroll: true });
                }
            })
            .catch(() => {
                loaded = false; // let the next open retry
                body.innerHTML = '<div class="ws-meilisearch-chat-panel__status ws-meilisearch-chat-panel__status--error">'
                    + escapeHtml(errorText) + '</div>';
            });
    };

    const open = () => {
        if (opened) {
            return;
        }
        opened = true;
        loadChat();
        panel.hidden = false;
        // Force a reflow so the CSS transition kicks in instead of
        // jumping from display:none → opacity:1 in one paint.
        // eslint-disable-next-line no-unused-expressions
        panel.offsetHeight;
        panel.classList.add('is-open');
        bubble.classList.add('is-active');
        bubble.setAttribute('aria-expanded', 'true');
        // Move focus into the panel so keyboard users land here, not
        // back on the page underneath.
        closeBtn.focus({ preventScroll: true });
    };

    const close = () => {
        if (!opened) {
            return;
        }
        opened = false;
        panel.classList.remove('is-open');
        bubble.classList.remove('is-active');
        bubble.setAttribute('aria-expanded', 'false');
        // Wait for the transition to end before un-hiding so
        // screen readers don't see the panel mid-collapse.
        const onTransitionEnd = () => {
            panel.removeEventListener('transitionend', onTransitionEnd);
            if (!opened) {
                panel.hidden = true;
            }
        };
        panel.addEventListener('transitionend', onTransitionEnd);
        bubble.focus({ preventScroll: true });
    };

    bubble.addEventListener('click', () => {
        opened ? close() : open();
    });
    closeBtn.addEventListener('click', close);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && opened) {
            close();
        }
    });
    }
}());

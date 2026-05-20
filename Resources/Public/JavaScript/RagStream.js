/**
 * Minimal EventSource client for the ws_meilisearch RAG streaming
 * endpoint. ~80 lines, no framework, no build step.
 *
 * Wire it into a chat-style template by:
 *   1. Including this file via <script src="…/RagStream.js" defer></script>
 *   2. Adding data attributes on a wrapper element:
 *      <div data-ws-meilisearch-rag-stream
 *           data-endpoint="/_ws_meilisearch/rag/stream">
 *        <form data-rag-form>
 *          <input name="q" />
 *          <button>Ask</button>
 *        </form>
 *        <div data-rag-sources></div>
 *        <div data-rag-answer></div>
 *      </div>
 *
 * The client overlays nicely on the existing GET-only PRG plugin —
 * pages without JS still get the regular sync ask() round-trip, pages
 * with JS get streaming on top.
 */
(function () {
    'use strict';

    function init(root) {
        const endpoint = root.dataset.endpoint || '/_ws_meilisearch/rag/stream';
        const form = root.querySelector('[data-rag-form]');
        const sourcesEl = root.querySelector('[data-rag-sources]');
        const answerEl = root.querySelector('[data-rag-answer]');
        if (!form || !answerEl) {
            return;
        }

        let currentStream = null;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const data = new FormData(form);
            const q = (data.get('q') || '').toString().trim();
            if (q === '') return;
            if (currentStream) currentStream.close();
            ask(q);
        });

        function ask(q) {
            if (sourcesEl) sourcesEl.textContent = '';
            answerEl.textContent = '';
            answerEl.dataset.streaming = 'true';

            const url = endpoint + '?q=' + encodeURIComponent(q);
            const es = new EventSource(url, { withCredentials: true });
            currentStream = es;

            es.addEventListener('sources', function (ev) {
                if (!sourcesEl) return;
                try {
                    const payload = JSON.parse(ev.data);
                    sourcesEl.innerHTML = renderSources(payload.sources || []);
                } catch (_) { /* ignore malformed frame */ }
            });

            es.addEventListener('token', function (ev) {
                try {
                    const payload = JSON.parse(ev.data);
                    answerEl.append(payload.text || '');
                } catch (_) { /* ignore */ }
            });

            es.addEventListener('done', function (ev) {
                es.close();
                currentStream = null;
                answerEl.dataset.streaming = 'false';
                try {
                    const payload = JSON.parse(ev.data);
                    if (Array.isArray(payload.citedIds) && payload.citedIds.length > 0) {
                        markCitedSources(sourcesEl, payload.citedIds);
                    }
                } catch (_) { /* ignore */ }
            });

            es.addEventListener('failed', terminate.bind(null, 'Sorry, something went wrong: '));
            es.addEventListener('no_context', terminate.bind(null, 'No matching documents found.', false));
            es.addEventListener('disabled', terminate.bind(null, 'RAG is not configured for this site.', false));

            function terminate(prefix, fromEvent = true) {
                es.close();
                currentStream = null;
                answerEl.dataset.streaming = 'false';
                let msg = prefix;
                if (fromEvent && arguments.length > 2) {
                    try {
                        const payload = JSON.parse(arguments[2].data);
                        if (payload.error) msg += payload.error;
                    } catch (_) { /* ignore */ }
                }
                answerEl.textContent = msg;
            }

            es.onerror = function () {
                if (es.readyState === EventSource.CLOSED) return;
                es.close();
                currentStream = null;
                answerEl.dataset.streaming = 'false';
                answerEl.textContent = 'Connection to the server was interrupted.';
            };
        }
    }

    function renderSources(sources) {
        if (sources.length === 0) return '';
        const items = sources.map(function (s) {
            const id = (s.id || '').toString();
            const title = (s.title || '').toString();
            const url = (s.publicUrl || '').toString();
            const linked = url
                ? '<a href="' + escapeAttr(url) + '">' + escapeText(title) + '</a>'
                : escapeText(title);
            return '<li data-source-id="' + escapeAttr(id) + '"><code>' + escapeText(id) + '</code> ' + linked + '</li>';
        }).join('');
        return '<strong>Sources:</strong><ul>' + items + '</ul>';
    }

    function markCitedSources(container, citedIds) {
        if (!container) return;
        const set = Object.create(null);
        citedIds.forEach(function (id) { set[id] = true; });
        container.querySelectorAll('[data-source-id]').forEach(function (el) {
            if (set[el.dataset.sourceId]) {
                el.classList.add('is-cited');
            }
        });
    }

    function escapeText(s) {
        return String(s).replace(/[&<>]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
        });
    }

    function escapeAttr(s) {
        return escapeText(s).replace(/"/g, '&quot;');
    }

    document.querySelectorAll('[data-ws-meilisearch-rag-stream]').forEach(init);
})();

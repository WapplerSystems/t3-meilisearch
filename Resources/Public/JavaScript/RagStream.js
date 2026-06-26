/**
 * EventSource client for the ws_meilisearch RAG streaming endpoint.
 * Vanilla JS, no framework, no build step.
 *
 * Turns the GET-only PRG RAG plugin into an AJAX chat: it intercepts the
 * form submit, streams the answer token-by-token from
 * /_ws_meilisearch/rag/stream, and renders sources + decision-support
 * suggestions client-side — without reloading the page. Without JS the
 * plugin still works as a normal synchronous round-trip.
 *
 * Wiring (see Templates/Rag/Form.html):
 *   <div data-ws-meilisearch-rag-stream data-endpoint="/_ws_meilisearch/rag/stream">
 *     <form data-rag-form> … name="tx_wsmeilisearch_rag[q]" … </form>
 *     <div data-rag-sources></div>
 *     <div data-rag-answer></div>
 *     <div data-rag-suggestions data-heading="…"></div>
 *   </div>
 *
 * Conversation memory works because the chat page sets the fe_typo_user
 * session cookie on load; the SSE request carries it (withCredentials).
 */
(function () {
    'use strict';

    function init(root) {
        const endpoint = root.dataset.endpoint || '/_ws_meilisearch/rag/stream';
        const form = root.querySelector('[data-rag-form]');
        const sourcesEl = root.querySelector('[data-rag-sources]');
        const answerEl = root.querySelector('[data-rag-answer]');
        const suggestionsEl = root.querySelector('[data-rag-suggestions]');
        if (!form || !answerEl) {
            return;
        }
        const inputEl = form.querySelector('input[name="tx_wsmeilisearch_rag[q]"], input[name="q"]');
        const submitBtn = form.querySelector('[data-ws-meilisearch-rag-submit], button[type="submit"]');
        const submitOriginal = submitBtn ? submitBtn.innerHTML : '';

        let currentStream = null;

        function setBusy(busy) {
            if (!submitBtn) return;
            submitBtn.disabled = busy;
            if (busy) {
                submitBtn.setAttribute('aria-busy', 'true');
            } else {
                submitBtn.removeAttribute('aria-busy');
                submitBtn.innerHTML = submitOriginal;
            }
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const q = readQuestion();
            if (q === '') return;
            if (currentStream) currentStream.close();
            ask(q);
        });

        if (suggestionsEl) {
            // followup / refine suggestions re-ask through the stream; recommend
            // suggestions are plain links handled by the browser.
            suggestionsEl.addEventListener('click', function (e) {
                const btn = e.target.closest('[data-suggestion-value]');
                if (!btn) return;
                e.preventDefault();
                const value = btn.getAttribute('data-suggestion-value') || '';
                if (value === '') return;
                if (inputEl) inputEl.value = value;
                if (currentStream) currentStream.close();
                ask(value);
            });
        }

        function readQuestion() {
            if (inputEl) return inputEl.value.trim();
            const data = new FormData(form);
            return (data.get('q') || '').toString().trim();
        }

        function ask(q) {
            if (sourcesEl) sourcesEl.textContent = '';
            if (suggestionsEl) suggestionsEl.innerHTML = '';
            answerEl.textContent = '';
            answerEl.dataset.streaming = 'true';
            setBusy(true);

            const url = endpoint + '?q=' + encodeURIComponent(q);
            const es = new EventSource(url, { withCredentials: true });
            currentStream = es;
            let closeTimer = null;
            function finish() {
                if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
                es.close();
                currentStream = null;
            }

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
                // Finalize the answer immediately, but keep the stream open: the
                // optional `suggestions` frame is emitted AFTER `done`. The
                // `end` sentinel closes us; the timer is a safety net so a
                // missing `end` can't trigger EventSource auto-reconnect.
                answerEl.dataset.streaming = 'false';
                setBusy(false);
                try {
                    const payload = JSON.parse(ev.data);
                    const finalText = typeof payload.answer === 'string' && payload.answer !== ''
                        ? payload.answer
                        : answerEl.textContent;
                    answerEl.innerHTML = renderMarkdownLight(finalText);
                    if (Array.isArray(payload.citedIds) && payload.citedIds.length > 0) {
                        markCitedSources(sourcesEl, payload.citedIds);
                    }
                } catch (_) { /* ignore */ }
                closeTimer = setTimeout(finish, 15000);
            });

            es.addEventListener('suggestions', function (ev) {
                if (suggestionsEl) {
                    try {
                        const payload = JSON.parse(ev.data);
                        renderSuggestions(payload.suggestions || []);
                    } catch (_) { /* ignore */ }
                }
            });

            es.addEventListener('end', function () { finish(); });

            es.addEventListener('failed', terminate.bind(null, 'Sorry, something went wrong: '));
            es.addEventListener('no_context', terminate.bind(null, 'No matching documents found.', false));
            es.addEventListener('disabled', terminate.bind(null, 'RAG is not configured for this site.', false));

            function terminate(prefix, fromEvent = true) {
                finish();
                answerEl.dataset.streaming = 'false';
                setBusy(false);
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
                if (es.readyState === EventSource.CLOSED) { finish(); return; }
                finish();
                answerEl.dataset.streaming = 'false';
                setBusy(false);
                answerEl.textContent = 'Connection to the server was interrupted.';
            };
        }

        function renderSuggestions(items) {
            if (!suggestionsEl) return;
            if (!items.length) { suggestionsEl.innerHTML = ''; return; }
            const heading = suggestionsEl.dataset.heading || '';
            const buttons = items.map(function (s) {
                const label = escapeText((s.label || '').toString());
                const value = (s.value || '').toString();
                const type = (s.type || 'followup').toString();
                if (type === 'recommend') {
                    return '<a class="ws-meilisearch-rag-suggestion ws-meilisearch-rag-suggestion--recommend btn btn-sm btn-outline-secondary"'
                        + ' href="' + escapeAttr(value) + '" rel="noopener">' + label + '</a>';
                }
                return '<button type="button"'
                    + ' class="ws-meilisearch-rag-suggestion ws-meilisearch-rag-suggestion--' + escapeAttr(type) + ' btn btn-sm btn-outline-primary"'
                    + ' data-suggestion-value="' + escapeAttr(value) + '">' + label + '</button>';
            }).join('');
            suggestionsEl.innerHTML =
                (heading ? '<small class="text-muted d-block mb-2">' + escapeText(heading) + '</small>' : '')
                + '<div class="d-flex flex-wrap gap-2">' + buttons + '</div>';
        }
    }

    function renderSources(sources) {
        if (sources.length === 0) return '';
        const items = sources.map(function (s) {
            const id = (s.id || '').toString();
            const title = (s.title || '').toString();
            const url = (s.uri || s.publicUrl || '').toString();
            const linked = url
                ? '<a href="' + escapeAttr(url) + '">' + escapeText(title) + '</a>'
                : escapeText(title);
            return '<li data-source-id="' + escapeAttr(id) + '"><code>' + escapeText(id) + '</code> ' + linked + '</li>';
        }).join('');
        return '<strong>Quellen:</strong><ul>' + items + '</ul>';
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

    function renderMarkdownLight(text) {
        return escapeText(text).replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');
    }

    function escapeAttr(s) {
        return escapeText(s).replace(/"/g, '&quot;');
    }

    function boot() {
        document.querySelectorAll('[data-ws-meilisearch-rag-stream]').forEach(init);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

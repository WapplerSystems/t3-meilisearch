/**
 * EventSource client for the ws_meilisearch RAG streaming endpoint.
 * Vanilla JS, no framework, no build step.
 *
 * Turns the GET-only PRG RAG plugin into an AJAX chat: it intercepts the
 * form submit and appends each Q&A as a streamed turn to the
 * [data-rag-thread] transcript — same markup as the server-rendered
 * history (Partials/Rag/Thread.html) — without reloading the page.
 * The answer streams token-by-token; sources and decision-support
 * suggestions render inside that turn. Without JS the plugin still works
 * as a normal synchronous round-trip (Ask.html).
 *
 * Wiring (Templates/Rag/Form.html):
 *   <div data-ws-meilisearch-rag-stream data-endpoint="…"
 *        data-label-you data-label-assistant data-label-sources data-label-suggestions>
 *     <form data-rag-form> … name="tx_wsmeilisearch_rag[q]" … </form>
 *     <ol data-rag-thread> … server history … </ol>
 *   </div>
 *
 * Frame order from the server: sources → token* → done → suggestions? → end.
 * We finalize the answer on `done` but only close the stream on `end`
 * (with a safety timeout), so the suggestions frame — generated after the
 * answer — still arrives without EventSource auto-reconnecting.
 *
 * Conversation memory works because the chat page sets the fe_typo_user
 * session cookie on load; the SSE request carries it (withCredentials).
 */
(function () {
    'use strict';

    function init(root) {
        // Idempotency: boot() runs on DOMContentLoaded and the chat widget
        // also calls the exported init on injected markup — never wire twice.
        if (root.dataset.wsmsStreamInit === '1') {
            return;
        }
        root.dataset.wsmsStreamInit = '1';

        const endpoint = root.dataset.endpoint || '/_ws_meilisearch/rag/stream';
        const form = root.querySelector('[data-rag-form]');
        const thread = root.querySelector('[data-rag-thread]');
        if (!form || !thread) {
            return;
        }
        const labels = {
            you: root.dataset.labelYou || 'You',
            assistant: root.dataset.labelAssistant || 'Assistant',
            sources: root.dataset.labelSources || 'Sources',
            suggestions: root.dataset.labelSuggestions || ''
        };
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

        function readQuestion() {
            if (inputEl) return inputEl.value.trim();
            return (new FormData(form).get('q') || '').toString().trim();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const q = readQuestion();
            if (q === '') return;
            if (currentStream) currentStream.close();
            ask(q);
        });

        // followup / refine suggestions re-ask through the stream; recommend
        // suggestions are plain links handled by the browser. Delegated on the
        // transcript so it covers every appended turn.
        thread.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-suggestion-value]');
            if (!btn) return;
            e.preventDefault();
            const value = btn.getAttribute('data-suggestion-value') || '';
            if (value === '') return;
            if (currentStream) currentStream.close();
            ask(value);
        });

        // Reset link: when the chat is embedded inline (no iframe) a normal
        // navigation would yank the whole host page away. Intercept it — clear
        // the transcript and fire the reset URL in the background to drop the
        // server-side conversation session.
        root.addEventListener('click', function (e) {
            const reset = e.target.closest('[data-rag-reset]');
            if (!reset) return;
            e.preventDefault();
            const href = reset.getAttribute('href') || '';
            if (href) {
                fetch(href, { credentials: 'include' }).catch(function () { /* best effort */ });
            }
            thread.innerHTML = '';
            if (inputEl) { inputEl.value = ''; inputEl.focus(); }
        });

        function appendTurn(question) {
            const li = document.createElement('li');
            li.className = 'ws-meilisearch-rag-turn mb-3';
            li.innerHTML =
                '<div class="card mb-2"><div class="card-body py-2">'
                + '<small class="text-muted d-block mb-1">' + escapeText(labels.you) + '</small>'
                + '<p class="card-text mb-0">' + escapeText(question) + '</p>'
                + '</div></div>'
                + '<div class="card border-primary"><div class="card-body py-2">'
                + '<small class="text-primary d-block mb-1">' + escapeText(labels.assistant) + '</small>'
                + '<p class="card-text mb-0 ws-meilisearch-rag-answer" data-streaming="true" style="white-space: pre-wrap;"></p>'
                + '<div class="ws-meilisearch-rag-sources mt-2"></div>'
                + '<div class="ws-meilisearch-rag-suggestions mt-2"></div>'
                + '</div></div>';
            thread.appendChild(li);
            li.scrollIntoView({ block: 'nearest' });
            return {
                answerEl: li.querySelector('.ws-meilisearch-rag-answer'),
                sourcesEl: li.querySelector('.ws-meilisearch-rag-sources'),
                suggestionsEl: li.querySelector('.ws-meilisearch-rag-suggestions')
            };
        }

        function ask(q) {
            const turn = appendTurn(q);
            if (inputEl) inputEl.value = '';
            setBusy(true);

            const es = new EventSource(endpoint + '?q=' + encodeURIComponent(q), { withCredentials: true });
            currentStream = es;
            let closeTimer = null;
            let acc = '';
            function finish() {
                if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
                es.close();
                currentStream = null;
            }

            es.addEventListener('sources', function (ev) {
                try {
                    const p = JSON.parse(ev.data);
                    turn.sourcesEl.innerHTML = renderSources(p.sources || [], labels.sources);
                } catch (_) { /* ignore */ }
            });

            es.addEventListener('token', function (ev) {
                try {
                    acc += JSON.parse(ev.data).text || '';
                    // Hide inline [id=…] citation markers — the sources are
                    // listed separately under the answer.
                    turn.answerEl.textContent = stripCitations(acc);
                    turn.answerEl.scrollIntoView({ block: 'nearest' });
                } catch (_) { /* ignore */ }
            });

            es.addEventListener('done', function (ev) {
                // Finalize the answer now; keep the stream open for the trailing
                // suggestions frame and close on `end` (safety timeout guards
                // against a missing `end` triggering an auto-reconnect).
                turn.answerEl.dataset.streaming = 'false';
                setBusy(false);
                try {
                    const p = JSON.parse(ev.data);
                    const finalText = typeof p.answer === 'string' && p.answer !== '' ? p.answer : acc;
                    turn.answerEl.innerHTML = renderMarkdownLight(stripCitations(finalText));
                    if (Array.isArray(p.citedIds) && p.citedIds.length > 0) {
                        markCitedSources(turn.sourcesEl, p.citedIds);
                    }
                } catch (_) { /* ignore */ }
                closeTimer = setTimeout(finish, 15000);
            });

            es.addEventListener('suggestions', function (ev) {
                try {
                    renderSuggestions(turn.suggestionsEl, JSON.parse(ev.data).suggestions || [], labels.suggestions);
                } catch (_) { /* ignore */ }
            });

            es.addEventListener('end', function () { finish(); });

            // Terminal clarify frame: the assistant asks one question back
            // instead of answering. Render it as the assistant turn (styled as
            // a clarification) and close the stream — no sources/suggestions
            // follow. The user's next message answers it.
            es.addEventListener('clarify', function (ev) {
                turn.answerEl.dataset.streaming = 'false';
                setBusy(false);
                var q = '';
                try { q = (JSON.parse(ev.data).question || '').toString(); } catch (_) { /* ignore */ }
                turn.answerEl.innerHTML = renderMarkdownLight(stripCitations(q));
                turn.answerEl.classList.add('ws-meilisearch-rag-answer--clarify');
                finish();
            });

            es.addEventListener('failed', terminate.bind(null, 'Sorry, something went wrong: '));
            es.addEventListener('no_context', terminate.bind(null, 'No matching documents found.', false));
            es.addEventListener('disabled', terminate.bind(null, 'RAG is not configured for this site.', false));

            function terminate(prefix, fromEvent = true) {
                finish();
                turn.answerEl.dataset.streaming = 'false';
                setBusy(false);
                let msg = prefix;
                if (fromEvent && arguments.length > 2) {
                    try {
                        const p = JSON.parse(arguments[2].data);
                        if (p.error) msg += p.error;
                    } catch (_) { /* ignore */ }
                }
                turn.answerEl.textContent = msg;
            }

            es.onerror = function () {
                const closed = es.readyState === EventSource.CLOSED;
                finish();
                if (closed) return;
                turn.answerEl.dataset.streaming = 'false';
                setBusy(false);
                if (turn.answerEl.textContent.trim() === '') {
                    turn.answerEl.textContent = 'Connection to the server was interrupted.';
                }
            };
        }
    }

    function renderSuggestions(container, items, heading) {
        if (!container) return;
        if (!items.length) { container.innerHTML = ''; return; }
        const buttons = items.map(function (s) {
            const label = escapeText((s.label || '').toString());
            const value = (s.value || '').toString();
            const type = (s.type || 'followup').toString();
            if (type === 'recommend') {
                return '<a class="ws-meilisearch-rag-suggestion ws-meilisearch-rag-suggestion--recommend btn btn-sm btn-outline-secondary"'
                    + ' href="' + escapeAttr(value) + '" target="_blank" rel="noopener">' + label + '</a>';
            }
            return '<button type="button"'
                + ' class="ws-meilisearch-rag-suggestion ws-meilisearch-rag-suggestion--' + escapeAttr(type) + ' btn btn-sm btn-outline-primary"'
                + ' data-suggestion-value="' + escapeAttr(value) + '">' + label + '</button>';
        }).join('');
        container.innerHTML =
            (heading ? '<small class="text-muted d-block mb-2">' + escapeText(heading) + '</small>' : '')
            + '<div class="d-flex flex-wrap gap-2">' + buttons + '</div>';
    }

    function renderSources(sources, label) {
        if (sources.length === 0) return '';
        const items = sources.map(function (s) {
            const id = (s.id || '').toString();
            const title = (s.title || '').toString();
            const type = (s.type || '').toString();
            const url = (s.uri || s.publicUrl || '').toString();
            // Help / knowledge-resource hits are the internal RAG grounding
            // corpus — show the title but don't link it or expose the raw id.
            if (type === 'knowledge_resource') {
                return '<li data-source-id="' + escapeAttr(id) + '">' + escapeText(title) + '</li>';
            }
            const linked = url
                ? '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener">' + escapeText(title) + '</a>'
                : escapeText(title);
            return '<li data-source-id="' + escapeAttr(id) + '"><code>' + escapeText(id) + '</code> ' + linked + '</li>';
        }).join('');
        return '<small class="text-muted d-block mb-1">' + escapeText(label) + '</small><ul>' + items + '</ul>';
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

    // Remove inline citation brackets like "[id=help-66]" or
    // "[id=help-66, id=pages-7]" from the displayed answer (incl. a preceding
    // space). Citations are surfaced in the separate sources list instead.
    function stripCitations(text) {
        return String(text).replace(/\s*\[\s*id\s*=\s*[^\]]*\]/gi, '');
    }

    function renderMarkdownLight(text) {
        return escapeText(text).replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');
    }

    function escapeAttr(s) {
        return escapeText(s).replace(/"/g, '&quot;');
    }

    function boot(scope) {
        (scope || document).querySelectorAll('[data-ws-meilisearch-rag-stream]').forEach(init);
    }
    // Exported so the chat widget can wire markup it injects after page load.
    window.wsMeilisearchRagStreamInit = boot;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(); });
    } else {
        boot();
    }
})();

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
            suggestions: root.dataset.labelSuggestions || '',
            loading: root.dataset.labelLoading || 'Generating answer…'
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
                // The answer is built faded in place with the spinner over it,
                // so the field is never blank while the model is working.
                + '<div class="ws-meilisearch-rag-answer-wrap">'
                + '<p class="card-text mb-0 ws-meilisearch-rag-answer" data-streaming="true" aria-busy="true" style="white-space: pre-wrap;"></p>'
                + '<div class="ws-meilisearch-rag-spinner" role="status">'
                + '<span class="ws-meilisearch-rag-spinner__label">' + escapeText(labels.loading) + '</span>'
                + '</div>'
                + '</div>'
                + '<div class="ws-meilisearch-rag-sources mt-2"></div>'
                + '<div class="ws-meilisearch-rag-suggestions mt-2"></div>'
                + '</div></div>';
            thread.appendChild(li);
            li.scrollIntoView({ block: 'nearest' });
            return {
                answerEl: li.querySelector('.ws-meilisearch-rag-answer'),
                spinnerEl: li.querySelector('.ws-meilisearch-rag-spinner'),
                sourcesEl: li.querySelector('.ws-meilisearch-rag-sources'),
                suggestionsEl: li.querySelector('.ws-meilisearch-rag-suggestions')
            };
        }

        // One place to end the "still working" state: the fade, the spinner
        // and aria-busy have to be cleared together on every path that
        // finishes a turn — answer, clarification, error or dropped stream.
        function stopStreaming(turn) {
            turn.answerEl.dataset.streaming = 'false';
            turn.answerEl.removeAttribute('aria-busy');
            if (turn.spinnerEl) {
                turn.spinnerEl.hidden = true;
            }
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
                    turn.sources = Array.isArray(p.sources) ? p.sources : [];
                    turn.sourcesEl.innerHTML = renderSources(p.sources || [], labels.sources);
                } catch (_) { /* ignore */ }
            });

            es.addEventListener('token', function (ev) {
                try {
                    acc += JSON.parse(ev.data).text || '';
                    // Hide inline [id=…] citation markers — the sources are
                    // listed separately under the answer.
                    turn.answerEl.textContent = stripCitations(acc, turn.sources);
                    turn.answerEl.scrollIntoView({ block: 'nearest' });
                } catch (_) { /* ignore */ }
            });

            es.addEventListener('done', function (ev) {
                // Finalize the answer now; keep the stream open for the trailing
                // suggestions frame and close on `end` (safety timeout guards
                // against a missing `end` triggering an auto-reconnect).
                stopStreaming(turn);
                setBusy(false);
                try {
                    const p = JSON.parse(ev.data);
                    const finalText = typeof p.answer === 'string' && p.answer !== '' ? p.answer : acc;
                    turn.answerEl.innerHTML = renderAnswerHtml(finalText, turn.sources);
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
                stopStreaming(turn);
                setBusy(false);
                var q = '';
                try { q = (JSON.parse(ev.data).question || '').toString(); } catch (_) { /* ignore */ }
                turn.answerEl.innerHTML = renderMarkdownLight(stripCitations(q, turn.sources));
                turn.answerEl.classList.add('ws-meilisearch-rag-answer--clarify');
                // Offer the named alternatives as buttons. They reuse the
                // suggestion markup, so the delegated click handler picks them
                // up and asks with the chosen wording — no extra wiring, and
                // the visitor does not have to retype a product name.
                var optionen = [];
                try { optionen = (JSON.parse(ev.data).options || []); } catch (_) { /* ignore */ }
                if (Array.isArray(optionen) && optionen.length > 1) {
                    // Server-composed pairs: the button reads just the choice,
                    // clicking it asks the original question with the choice
                    // appended, so the topic cannot get lost on the way.
                    renderSuggestions(turn.suggestionsEl, optionen.map(function (o) {
                        return { type: 'clarify', label: (o.label || '').toString(), value: (o.value || '').toString() };
                    }), '');
                }
                finish();
            });

            es.addEventListener('failed', terminate.bind(null, 'Sorry, something went wrong: '));
            es.addEventListener('no_context', terminate.bind(null, 'No matching documents found.', false));
            es.addEventListener('disabled', terminate.bind(null, 'RAG is not configured for this site.', false));

            function terminate(prefix, fromEvent = true) {
                finish();
                stopStreaming(turn);
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
                stopStreaming(turn);
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
            // Label plus qualifier, so the list distinguishes the same topic
            // in several releases just as the inline citations do.
            const base = (s.citationLabel || s.title || '').toString();
            const qualifier = (s.citationQualifier || '').toString().trim();
            const title = qualifier !== '' ? base + ' (' + qualifier + ')' : base;
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
            // The raw document id stays in the data attribute for debugging
            // but is not shown - it means nothing to a visitor.
            return '<li data-source-id="' + escapeAttr(id) + '">' + linked + '</li>';
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

    // Index the sources of a turn by their document id.
    function sourcesById(sources) {
        const byId = Object.create(null);
        (sources || []).forEach(function (src) {
            const id = (src && src.id ? src.id : '').toString();
            if (id !== '') { byId[id] = src; }
        });
        return byId;
    }

    // Walk the citation blocks the model emits and hand each one to `render`.
    // Two formats occur in practice: the "[id=pages-7]" the prompt asks for
    // and the bare "[pages-7, pages-9]" the model falls back to, so tokens are
    // matched against the actual source ids rather than against a fixed shape.
    // A block whose tokens are all unknown is prose ("[NOTE]") and stays as it
    // is; a block of purely knowledge_resource hits is dropped, because that
    // corpus is internal grounding and must not surface as a link or a raw id.
    function rewriteCitations(text, sources, render) {
        const byId = sourcesById(sources);
        return String(text).replace(/(\s*)\[([^\[\]]+)\]/g, function (whole, leadingWs, inner) {
            const tokens = inner.match(/[A-Za-z0-9_:.-]+/g);
            if (!tokens) { return whole; }
            const matched = [];
            const seen = Object.create(null);
            let knowledgeResourceMatches = 0;
            tokens.forEach(function (token) {
                const src = byId[token];
                if (!src || seen[token]) { return; }
                if ((src.type || '').toString() === 'knowledge_resource') {
                    knowledgeResourceMatches++;
                    return;
                }
                seen[token] = true;
                matched.push({ id: token, src: src });
            });
            if (matched.length === 0) {
                return knowledgeResourceMatches > 0 ? '' : whole;
            }
            // The whole block goes to the renderer, not one token at a time,
            // so it can group documents that share a citation label.
            return leadingWs + render(matched);
        });
    }

    // Plain-text pass used while tokens are still arriving: drop the markers
    // rather than link them, so they don't flicker into place mid-stream. The
    // second replace hides a citation the model has only half-written yet.
    function stripCitations(text, sources) {
        return rewriteCitations(text, sources, function () { return ''; })
            .replace(/\s*\[[^\[\]]*$/, '');
    }

    // Final render: citation markers become links to the cited document.
    // Mirrors RagAnswer::getAnswerHtml() so the streamed answer and the
    // server-rendered one look the same, down to the markup and the tooltip.
    function renderAnswerHtml(text, sources) {
        const linked = rewriteCitations(escapeText(text), sources, function (matched) {
            // Group by citation label so three releases of one topic read
            // "[Install packages (26 - Revit, 25 - AutoCAD)]" instead of
            // repeating the title. Each document keeps its own link, carried
            // by its qualifier. citationLabel/citationQualifier come from
            // RagCitationLabelsEvent listeners; plain title otherwise.
            const order = [];
            const groups = Object.create(null);
            matched.forEach(function (hit) {
                const label = (hit.src.citationLabel || hit.src.title || '').toString().trim() || hit.id;
                if (!groups[label]) { groups[label] = []; order.push(label); }
                groups[label].push({
                    id: hit.id,
                    qualifier: (hit.src.citationQualifier || '').toString().trim(),
                    uri: (hit.src.uri || hit.src.publicUrl || '').toString(),
                    type: (hit.src.type || '').toString().trim()
                });
            });

            return order.map(function (label) {
                const members = groups[label];
                const qualified = members.filter(function (m) { return m.qualifier !== ''; });
                // A single document, or several that brought no qualifier to
                // tell them apart: link the label itself, once per document.
                if (members.length === 1 || qualified.length === 0) {
                    return members.map(function (m) {
                        const text = m.qualifier !== '' ? label + ' (' + m.qualifier + ')' : label;
                        return '[' + citationAnchor(m, text) + ']';
                    }).join('');
                }
                // Several documents sharing a label: say it once as plain
                // text and turn each qualifier into the link.
                const links = members.map(function (m) {
                    return citationAnchor(m, m.qualifier !== '' ? m.qualifier : label);
                });
                return '[' + escapeText(label) + ' (' + links.join(', ') + ')]';
            }).join('');
        });
        // **bold** last, exactly as the server does: escaping left the
        // asterisks alone and the citation titles must not be re-scanned.
        return linked.replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');
    }

    // One citation link. Documents without a uri become an <abbr> so the
    // tooltip still names what was cited.
    function citationAnchor(member, text) {
        const tooltip = member.type !== '' ? member.type + ' \u00b7 ' + member.id : member.id;
        if (member.uri === '') {
            return '<abbr title="' + escapeAttr(tooltip) + '">' + escapeText(text) + '</abbr>';
        }
        return '<a href="' + escapeAttr(member.uri) + '" title="' + escapeAttr(tooltip)
            + '" rel="noopener" class="ws-meilisearch-rag-citation">' + escapeText(text) + '</a>';
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

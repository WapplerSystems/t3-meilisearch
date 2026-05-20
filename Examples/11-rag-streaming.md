# 11 — RAG streaming via Server-Sent Events

Tokens render as they're generated instead of waiting for the whole
answer. Visitor types a question, sees the matched sources within a
second, then watches the LLM "type" its response in real time.

Same RAG logic as [05](05-rag-anthropic.md) — just over a different
transport.

## Stack

- Search + hybrid + RAG stack from earlier examples
- Webserver that doesn't buffer responses (see "Webserver caveats" below)
- A few lines of JS in the page that hosts the RAG plugin

## Endpoint

The extension ships a PSR-15 middleware that listens on

```
/_ws_meilisearch/rag/stream
```

Frontend hits it with a `GET` carrying `q=<question>`. The middleware
resolves the site from the request attribute (so the same path serves
all sites; the response is per-site automatic), then streams SSE
frames:

```
event: sources
data: {"sources":[{"id":"file-35","title":"…","publicUrl":"…"}, …]}

event: token
data: {"text":"You can reset"}

event: token
data: {"text":" your password from"}

…

event: done
data: {"answer":"You can reset your password from … [id=file-35].","citedIds":["file-35"]}
```

Terminal alternatives instead of `done`:

```
event: failed       data: {"error":"…"}
event: no_context   data: []
event: disabled     data: []
```

## Site setting

No new settings — streaming reuses the same `meilisearch.rag.*`
configuration as the sync RAG plugin. Multi-turn conversation memory
(`meilisearch.rag.conversation.enabled=true`) **does** work via the
streaming endpoint: the middleware loads the conversation from the
session before streaming and writes the new turn back after the
`done` frame.

## JS client

Drop `Resources/Public/JavaScript/RagStream.js` from the extension
onto your page. The client picks up any wrapper with the
`data-ws-meilisearch-rag-stream` attribute.

Minimal page markup:

```html
<div data-ws-meilisearch-rag-stream
     data-endpoint="/_ws_meilisearch/rag/stream">
  <form data-rag-form>
    <input name="q" placeholder="Ask anything…" required />
    <button>Ask</button>
  </form>
  <div data-rag-sources></div>
  <div data-rag-answer></div>
</div>

<script src="/typo3conf/ext/ws_meilisearch/Resources/Public/JavaScript/RagStream.js" defer></script>
```

The client:

- POST→GET is not needed (no PRG required for streaming — each ask is
  a fresh EventSource).
- On `sources`, paints a sources list with `<li data-source-id>`.
- On `token`, appends text to `[data-rag-answer]`.
- On `done`, closes the stream and adds `class="is-cited"` to every
  source that actually got referenced.
- On `failed` / `no_context` / `disabled`, swaps the answer area for
  a status message.

Style is left to the site package — the JS only adds the
`is-cited` class and writes plain text, no inline CSS.

## Progressive enhancement

Plain HTML / no-JS visitors still get the regular sync `WsMeilisearch
/ Rag` plugin from [05](05-rag-anthropic.md). Cookie-less crawlers
that don't execute JS see the static form. So this works as an
*overlay* on the existing plugin; the streaming endpoint isn't a
replacement.

## Webserver caveats

SSE requires immediate flushing — every layer between PHP and the
browser must be unbuffered for the stream to actually stream.

**Nginx**:

```nginx
location /_ws_meilisearch/rag/stream {
    proxy_buffering off;
    fastcgi_buffering off;
    proxy_cache off;
}
```

The middleware already sets `X-Accel-Buffering: no` which Nginx 1.5+
honors at the response level — the config above is the safety net.

**Apache**: works out of the box with `mod_php`. With `mod_proxy_fcgi`,
add `flushpackets=on` to the ProxyPass directive.

**PHP**: `output_buffering = Off` in php.ini for the streaming
endpoint, or rely on the middleware's `while (ob_get_level()) ob_end_clean()`
opener (already in place).

## CLI verification (no JS needed)

```bash
curl -N 'https://example.com/_ws_meilisearch/rag/stream?q=How+do+I+reset+my+password'
```

(`-N` = `--no-buffer`; `curl` prints each frame as it arrives.)

Expected output:

```
retry: 5000

event: sources
data: {"sources":[{"id":"pages-42","title":"Account & login", …}, …]}

event: token
data: {"text":"You"}

event: token
data: {"text":" can"}

…

event: done
data: {"answer":"…","citedIds":["pages-42"]}
```

## When to use streaming vs sync

- **Streaming**: chat UIs where perceived latency matters, mobile
  users on slow connections, long answers (>1s).
- **Sync (Phase 4 plugin)**: traditional FAQ pages, backend tools,
  any context where the response will be processed programmatically
  before display.

Both paths share the same RagService — switching is a template-side
decision, not an indexer-side one.

## Listener events still fire

`BeforeRagQueryEvent` / `BeforeLlmCallEvent` / `AfterRagAnswerEvent`
all dispatch during the streaming flow too:

- `BeforeRagQueryEvent` runs once, before retrieval.
- `BeforeLlmCallEvent` runs once; if a listener sets `$event->response`
  to a cached string, the middleware emits it as a single `token`
  followed by `done` — no upstream LLM call.
- `AfterRagAnswerEvent` fires once with the accumulated final answer
  *after* the `done` frame has already been emitted (so listeners
  can log / analytics without delaying perceived latency).

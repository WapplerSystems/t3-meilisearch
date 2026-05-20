# ws_meilisearch — Meilisearch Search Backend for TYPO3

TYPO3 v14 extension providing Meilisearch-backed full-text search via the
[SEAL](https://github.com/php-cmsig/search) abstraction. Designed so the
search backend stays swappable (Meilisearch today, Typesense / Elasticsearch
tomorrow) without rewriting templates or services.

## Status

**All five phases wired and working.** Pages, news, and FAL files
(PDF / Office / RTF / EPUB / plain text via Apache Tika) all share a
single unified per-site index, faceted by `type`. Frontend Search
plugin renders GET forms with typo-tolerant search + click-to-filter
facets. Hybrid (keyword + semantic vector) search is available when
an embedder is configured. A second Extbase plugin exposes
Retrieval-Augmented Generation: search → context → LLM → cited answer,
with OpenAI, Anthropic, Ollama, and generic OpenAI-compatible REST
providers selectable per site. A backend module under System →
Meilisearch shows per-site index status, exposes Reindex / Rebuild
buttons, and includes an ad-hoc Search + RAG test form. A scheduler
task runs `indexAll` against one or all sites on a cron.

## Installation

The extension lives as a local package in `packages/wapplersystems/meilisearch/`,
already picked up by the root `composer.json`. To install:

```bash
ddev composer require wapplersystems/meilisearch:@dev
```

This pulls in:

- `cmsig/seal` — engine + schema abstraction
- `cmsig/seal-meilisearch-adapter` — Meilisearch backend
- `meilisearch/meilisearch-php` — official PHP SDK

## DDEV setup

Two services drop into `.ddev/`:

- `docker-compose.meilisearch.yaml` — Meilisearch server on port 7700 (also
  reachable via Traefik at `https://<project>.ddev.site:7701` for the
  built-in dashboard).
- `docker-compose.tika.yaml` — Apache Tika server on port 9998, used for
  text extraction from PDF / Office files (Phase 2). Optional — leave the
  `meilisearch.tika.url` site setting empty to disable FAL indexing.

After `ddev restart`:

```bash
ddev exec curl -s http://meilisearch:7700/health     # {"status":"available"}
ddev exec curl -s http://tika:9998/version           # Apache Tika 3.0.0
```

## Configuration

Enable the Site Set on the desired site in `config/sites/<id>/config.yaml`:

```yaml
dependencies:
  - wapplersystems/ws-meilisearch
```

Then set the connection in `config/sites/<id>/settings.yaml`:

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'
  deduplicateFiles: true     # opt-in — only index files referenced on this site
  tika:
    url: 'http://tika:9998'
    timeout: 60
    maxFileSize: 52428800
```

`deduplicateFiles` defaults to `false` (every site indexes every FAL
file). Set to `true` for strict per-site results — the indexer then
follows `sys_file_reference → page → site` and only includes files
referenced from at least one page of the current site. Files
referenced only from non-page records (e.g. `be_users.avatar`) are
skipped entirely.

Definitions live in `Configuration/Sets/WsMeilisearch/settings.definitions.yaml`
so settings are typed and editable through the Backend Sites module.

### Hybrid search (Phase 3)

To enable vector + keyword hybrid search, set `meilisearch.embedder.*`
in the site settings and enable the `vectorStore` experimental feature
on the Meilisearch server (one-time, server-wide):

```bash
ddev exec curl -s -X PATCH \
  -H 'Authorization: Bearer <master_key>' \
  -H 'Content-Type: application/json' \
  -d '{"vectorStore":true}' \
  http://meilisearch:7700/experimental-features
```

Then pick a source:

```yaml
# OpenAI
meilisearch:
  embedder:
    source: 'openAi'
    model: 'text-embedding-3-small'
    apiKey: '%env(OPENAI_API_KEY)%'
    semanticRatio: 0.5

# Ollama (self-hosted, no API key)
meilisearch:
  embedder:
    source: 'ollama'
    url: 'http://ollama:11434/api/embeddings'
    model: 'nomic-embed-text'

# Hugging Face Inference API
meilisearch:
  embedder:
    source: 'huggingFace'
    model: 'BAAI/bge-base-en-v1.5'

# User-provided vectors (advanced — every doc must ship `_vectors.default`)
meilisearch:
  embedder:
    source: 'userProvided'
    dimensions: 384
```

`ws_meilisearch:reindex --rebuild` pushes the embedder configuration to
Meilisearch before populating documents, so the first hybrid query
after rebuild sees a fully vectorized corpus. Without `--rebuild`,
existing docs are re-sent and re-vectorized in place.

Frontend: `?hybrid=1` on the results URL flips to hybrid mode; the
`hybridAvailable` flag is exposed to Fluid so the toggle stays hidden
on sites without an embedder. `semanticRatio` (0..1) is read from site
settings and can be overridden per request via the `options` parameter
of `SearchService::search()`.

### Retrieval-Augmented Generation (Phase 4)

Pick an LLM provider in site settings and the `WsMeilisearch / Rag`
Extbase plugin becomes a "ask the site" chat. Search runs first
(hybrid by default if an embedder is configured); the top hits become
context for the LLM, which returns a grounded answer with `[id=...]`
citation markers.

```yaml
# OpenAI
meilisearch:
  rag:
    provider: 'openAi'
    model: 'gpt-4o-mini'
    apiKey: '%env(OPENAI_API_KEY)%'
    temperature: 0.2

# Anthropic
meilisearch:
  rag:
    provider: 'anthropic'
    model: 'claude-haiku-4-5'
    apiKey: '%env(ANTHROPIC_API_KEY)%'

# Ollama (local, no key)
meilisearch:
  rag:
    provider: 'ollama'
    url: 'http://ollama:11434'
    model: 'llama3.1:8b'

# Any OpenAI-compatible endpoint (vLLM, Together, Groq, LM Studio, …)
meilisearch:
  rag:
    provider: 'rest'
    url: 'https://api.together.xyz'
    apiKey: '%env(TOGETHER_API_KEY)%'
    model: 'meta-llama/Llama-3-8b-chat-hf'
```

Citations: the default system prompt instructs the LLM to mark facts
with `[id=<hit-id>]` and the controller extracts them via regex,
returning a `citedIds` list alongside the rendered answer so the
template can show a "Sources" block.

Caching / replay: listen to `BeforeLlmCallEvent` and set `$response`
to a cached value to skip the LLM call entirely. Useful for tests and
for FAQ-style questions that don't need a fresh generation per visit.

CLI for debugging without rendering the FE plugin:
```bash
ddev exec vendor/bin/typo3 ws_meilisearch:ask "What is X?" main
```

Multi-turn conversation memory (opt-in):

```yaml
meilisearch:
  rag:
    conversation:
      enabled: true        # default false — each ask stays single-turn
      maxTurns: 3          # cap the prompt size; oldest pair drops first
      sessionKey: 'ws_meilisearch_rag_conversation'   # change to run multiple plugins independently
```

When enabled, the controller stores the last N (question, answer)
pairs in the anonymous TYPO3 frontend user session (cookie-backed by
TYPO3 itself). RagService splices them between the system prompt and
the new user turn, so the LLM sees:
`[system, prior_user, prior_assistant, …, current_user_with_context]`.
A new `?action=reset` URL on the RAG plugin clears the stored state
so a visitor can start over. Sources from past turns are not
re-displayed; the controller only keeps `citedIds` for the template
to show as "this answer cited X".

## Backend module (Phase 5)

After installing the extension, an admin-only entry **System → Meilisearch**
shows up. The overview action lists every site with:

- index name + live document count (queried from Meilisearch on render)
- embedder source from settings + an `active` / `not pushed` badge based on
  what Meilisearch actually has applied
- RAG provider from settings (or `disabled` when empty)
- per-row Reindex / Rebuild buttons (Rebuild prompts for confirmation
  because it drops the index — search is unavailable for the rebuild
  window)

The **Test search & RAG** sub-page lets an editor type a query and an
LLM question against any site without leaving the BE — useful for
verifying that a freshly tuned `documentTemplate` or `systemPrompt`
behaves as expected before pushing settings to production.

The **Diagnostics** sub-page shows, per site, the *desired* embedder
configuration (from `settings.yaml`) next to the *actual* one stored
on the Meilisearch server, plus the configured RAG provider with its
model / URL / conversation-memory flags. Two maintenance buttons:

- **Re-push embedder** — runs `EmbedderConfigurator::ensureForSite()`
  for the chosen site. Flashes one of *configured*, *unchanged*,
  *disabled*, *skipped* so admins can tell whether the call actually
  changed anything.
- **Ping provider** — sends a one-shot `ping → pong` round-trip to
  the configured LLM provider (bypassing retrieval, so it's a pure
  endpoint health check). Flashes the latency and a truncated reply,
  or the error message if the provider is unreachable / misconfigured.

## Scheduler task (Phase 5)

`FullReindexTask` registers under **Administration → Scheduler** as
*Meilisearch: Full Reindex*. TYPO3 v14 native task — fields are
TCA-driven on `tx_scheduler_task`, no `AdditionalFieldProviderInterface`:

- **Site identifier** — empty for all sites, or one TYPO3 site
  identifier (matches the directory under `config/sites/`).
- **Rebuild** — drop + recreate the Meilisearch index before
  populating. Only enable after schema changes; the index is
  unavailable for the duration.
- **Skip embedder push** — leave the embedder settings on Meilisearch
  untouched. Use for troubleshooting a wedged hybrid setup while still
  keeping the document corpus fresh.

Typical cadences:

- Nightly incremental: site=`main`, rebuild=off, skip-embedder=off
- After deploy with new SchemaProvider fields: one-shot run with
  rebuild=on, skip-embedder=off (recreates schema + re-vectorizes)
- After embedder rotation: rebuild=off, skip-embedder=off (forces a
  re-push of embedder settings)

## CLI

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:reindex                        # all sites
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main                    # one site, incremental
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild          # drop + recreate first
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --skip-embedder    # leave embedder config untouched

# RAG (Phase 4) — runs the configured LLM provider against the site index
ddev exec vendor/bin/typo3 ws_meilisearch:ask "How do I reset my password?" main
```

## What's wired

| Layer | Component | File |
|---|---|---|
| Plugin registration | Extbase plugin `WsMeilisearch / Search` (CType `wsmeilisearch_search`) | `ext_localconf.php`, `Configuration/TCA/Overrides/tt_content.php` |
| Site Set | `wapplersystems/ws-meilisearch` with typed settings + TypoScript | `Configuration/Sets/WsMeilisearch/*` |
| Indexing extension point | `SchemaProviderInterface` (auto-tagged via `_instanceof`) | `Classes/Domain/Schema/` |
| Default providers | Pages + tx_news (gated on EXT:news) + sys_file (one doc per site language with sys_file_metadata overlay) | `PageSchemaProvider.php`, `NewsSchemaProvider.php`, `FileSchemaProvider.php` |
| Engine factory | Reads site settings, builds unified SEAL Engine + Index | `Classes/Service/SearchEngineFactory.php` |
| Indexer | Iterates providers, dispatches lifecycle events, waits on Meilisearch async tasks | `Classes/Service/IndexerService.php` |
| Search service | Builds SEAL query (search + filters + facets), maps result; hybrid path bypasses SEAL to use Meilisearch SDK directly | `Classes/Service/SearchService.php` |
| Tika integration | Apache Tika REST client + sha1-keyed cache | `Classes/Service/Tika/` |
| Embedder configurator | Idempotent PATCH of per-index embedder settings, source-aware field allowlist, waits for async settingsUpdate | `Classes/Service/EmbedderConfigurator.php` |
| LLM provider abstraction | `LlmProviderInterface` with OpenAI / Anthropic / Ollama / generic REST implementations, picked per site by `LlmProviderRegistry` | `Classes/Service/Llm/` |
| RAG orchestrator | Retrieves hits → builds cited-context prompt → calls LLM → parses `[id=...]` citations → `RagAnswer` DTO | `Classes/Service/Rag/` |
| RAG plugin | Extbase plugin `WsMeilisearch / Rag` (CType `wsmeilisearch_rag`) with `form` + `ask` + `reset` actions | `Classes/Controller/RagController.php` |
| RAG streaming | SSE endpoint at `/_ws_meilisearch/rag/stream`, drop-in JS client renders tokens incrementally | `Classes/Middleware/RagStreamMiddleware.php`, `Resources/Public/JavaScript/RagStream.js` |
| RAG CLI | `ws_meilisearch:ask "question" [site]` for ad-hoc testing | `Classes/Command/AskCommand.php` |
| Backend module | System → Meilisearch: per-site index status, Reindex / Rebuild buttons, ad-hoc Search + RAG test forms | `Classes/Controller/Backend/OverviewController.php` |
| Scheduler task | TYPO3 v14 native task (TCA-driven, no AdditionalFieldProvider) for periodic reindex of one site or all | `Classes/Task/FullReindexTask.php` |
| Realtime sync (BE forms) | DataHandler hook → indexer (sys_file_metadata + sys_file_reference both translated to sys_file) | `Classes/DataHandling/RecordChangeListener.php` |
| Realtime sync (FAL storage) | PSR-14 listeners on AfterFileAdded / Deleted / Renamed / Moved / ContentsSet / Replaced / Copied / MetaDataUpdated / RemovedFromIndex | `Classes/DataHandling/FalEventListener.php` |
| Cross-site file dispatcher | Shared `reindex(uid)` / `remove(uid)` used by both sync paths | `Classes/DataHandling/FileLifecycleHandler.php` |
| CLI | `ws_meilisearch:reindex [site] [--rebuild]` | `Classes/Command/ReindexCommand.php` |
| Events (PSR-14) | Before/After Document Indexed, Before/After Search | `Classes/Event/` |
| Frontend templates | GET-only forms, auto-submit facets, PRG-redirect on stray POSTs | `Resources/Private/Templates/Search/` |

## Examples

End-to-end snippets in [`Examples/`](Examples/) — pick the closest
match to your setup and adapt:

| File | Topic |
|---|---|
| [01](Examples/01-minimal-keyword-search.md) | Minimal keyword search |
| [02](Examples/02-fal-with-tika.md) | FAL files via Apache Tika |
| [03](Examples/03-hybrid-openai.md) | Hybrid search with OpenAI embeddings |
| [04](Examples/04-hybrid-ollama.md) | Hybrid search with self-hosted Ollama |
| [05](Examples/05-rag-anthropic.md) | RAG chat with Anthropic Claude |
| [06](Examples/06-rag-conversation.md) | Multi-turn RAG conversation memory |
| [07](Examples/07-event-listener-prompt-cache.md) | Cache identical RAG calls via `BeforeLlmCallEvent` |
| [08](Examples/08-event-listener-query-rewriter.md) | Rewrite verbose user queries before retrieval |
| [09](Examples/09-custom-schema-provider.md) | Index a third-party extension's records |
| [10](Examples/10-programmatic-api.md) | Call `SearchService` / `RagService` from PHP |
| [11](Examples/11-rag-streaming.md) | RAG streaming via Server-Sent Events |

## Frontend plugin invariants

- **All forms are `method="get"`** — the result page must be fully reproducible
  from the URL so the browser back button never asks "Resubmit form?".
- **`resultsAction` PRG-redirects any POST to GET** as a defensive measure
  for third-party callers that might violate the GET convention.
- **`^tx_wsmeilisearch_search` is excluded from cHash** because GET form
  submission discards the action URL's query string. action / controller
  values are still validated by Extbase against the registered actions
  list, so a forged URL cannot invoke arbitrary controllers.
- **Facet checkboxes auto-submit on change** (`this.form.requestSubmit()`),
  so users don't need a separate "Apply filters" button.

## Adding a new record type

Implement `SchemaProviderInterface`. Auto-wired and auto-tagged via
`_instanceof` in `Configuration/Services.yaml`, no manual registration.

```php
final class ProductSchemaProvider implements SchemaProviderInterface { ... }
```

Optional `getAdditionalFields()` lets a provider contribute extra SEAL
schema fields (e.g. `price` as IntegerField sortable + filterable). The
factory dedupes by field name across providers.

## Roadmap

- **Phase 1** ✅ basic indexing + Fluid plugin with typo tolerance & facets
- **Phase 2** ✅ FAL/Tika indexing (PDF / Office / RTF / EPUB / plain text)
- **Phase 3** ✅ Hybrid search + auto-embeddings (OpenAI / HF / Ollama / REST / userProvided)
- **Phase 4** ✅ RAG module with configurable LLM provider (OpenAI / Anthropic / Ollama / REST)
- **Phase 5** ✅ Backend module + scheduler task

## Known Phase 2 limitations

- **No OCR** — `apache/tika:3.0.0.0` (base image, ~500 MB) ships without
  Tesseract. Swap to `apache/tika:3.0.0.0-full` (~1.5 GB) for OCR on
  scanned PDFs / images. Phase 3 will revisit.

## Known Phase 3 limitations

- **Meilisearch `vectorStore` experimental feature must be enabled** (one
  PATCH on `/experimental-features`, see "Hybrid search" above). Sending
  `embedders` settings to a server with the feature off returns a 400
  and aborts the reindex.
- **`userProvided` source requires every document to ship its own vectors**
  in `_vectors.default`. The default schema providers don't do that —
  use `userProvided` only as an integration point for downstream code
  that already has vectors, not for general-purpose search.
- **API-key rotation isn't auto-detected** — Meilisearch redacts the
  key on read-back, so the configurator can't diff "new" vs "redacted"
  to decide whether to PATCH. Touch any other embedder setting (or run
  `--rebuild`) to force a re-push after key rotation.
- **Hybrid result hits skip the SEAL adapter** — frontend code that
  inspects fields beyond the unified schema may see slightly different
  shapes between keyword and hybrid results.

## Known Phase 4 limitations

- **Streaming requires unbuffered hosting.** The
  `/_ws_meilisearch/rag/stream` SSE endpoint works out of the box in
  the DDEV stack, but production behind Nginx needs `proxy_buffering
  off` / `fastcgi_buffering off` on that path. See
  [`Examples/11-rag-streaming.md`](Examples/11-rag-streaming.md) for
  webserver config.
- **Conversation memory is opt-in.** When
  `meilisearch.rag.conversation.enabled=false` (the default), each
  `ask` is stateless. Set it to `true` to enable the multi-turn flow
  documented under "Retrieval-Augmented Generation (Phase 4)" — the
  anonymous frontend session holds the last N (Q, A) pairs and the
  controller splices them into every subsequent LLM call.
- **Citation extraction is regex-based.** Models that wrap markers in
  prose ("see [id=foo and id=bar]") only get the first id captured;
  models that ignore the citation instruction yield zero `citedIds`.
  Tune the system prompt per model.
- **No token budgeting.** `maxContextHits` × `maxContextChars` is a
  rough cap. A very long question or many large hits can blow past
  small-model context windows; provider returns the underlying API
  error, surfaced through `RagAnswer::failed`.
- **No cost / rate-limit guard.** Every frontend submission triggers an
  LLM call. Pair with `BeforeLlmCallEvent` listeners (response cache,
  per-session rate limit) for production deployments.
- **Retrieval query = user question, verbatim.** Verbose questions
  (e.g. "What is X about?") often miss the keyword retriever. Enable
  the hybrid path (`useHybrid: true` + configured embedder) or insert
  a query-rewriting `BeforeRagQueryEvent` listener.

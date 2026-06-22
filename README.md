# ws_meilisearch — Meilisearch Search Backend for TYPO3

TYPO3 v14 extension providing Meilisearch-backed full-text search via the
[SEAL](https://github.com/php-cmsig/search) abstraction. Designed so the
search backend stays swappable (Meilisearch today, Typesense / Elasticsearch
tomorrow) without rewriting templates or services.

## Features at a glance

**Indexing**

- Single unified per-site index, faceted by document `type`.
- Built-in schema providers: **pages** (via `lochmueller/index`), **news** (`tx_news`),
  **FAL files** (Tika-extracted PDF / Office / RTF / EPUB / plain text), and
  **knowledge resources** (curated DITA-OT / ZIP / URL imports).
- **Per-doc embeddings** stored under `_vectors.default` for hybrid search.
  Either Meilisearch fetches them via its REST embedder (auto-batched) or
  the extension precomputes them in PHP and pushes them with the document.
- **Content language detection** (n-gram, ISO 639-1) on every indexed
  document — a German PDF appearing in an EN-overlay gets `contentLanguage=de`
  and is filtered out for EN visitors.
- **Zero-downtime reindex** (opt-in): writes to a `<index>_draft` index and
  atomically swaps it into the primary on completion, so visitors never see
  a blank search during a reindex.
- **sys_file existence sweep** CLI to flag dead FAL rows `missing=1` before
  reindex — keeps the indexer from spending hours on AWS-SDK retries against
  tombstoned bucket objects.

**Search**

- **Typo tolerance** with per-attribute and per-word exclusion
  (`disableOnAttributes`, `disableOnWords`, `disableOnNumbers`) — keep brand
  / product tokens and version numbers exact.
- **Hybrid keyword + semantic** search when an embedder is configured.
- **Phrase search** (`"two words"`) and **negation** (`-token`) work out of
  the box.
- **Matching strategy** per call: `last` (drop trailing), `frequency` (drop
  most-frequent tokens first), `all` (strict AND — default for FE search).
- **Synonyms**, **stop-words** (with per-call override for RAG queries),
  **custom ranking rules**, **distinct attribute**, **searchCutoffMs**.
- **Faceted navigation** with disjunctive faceting for active facet
  attributes.
- **Restrict to active site language** + **contentLanguage filter** both
  applied on the search controller when opted in.

**Frontend surfaces**

- **`tx_wsmeilisearch_search`** Extbase plugin — Bootstrap-styled GET form
  with click-to-filter facets, AJAX result-fragment refresh, configurable
  per-plugin via FlexForm.
- **`tx_wsmeilisearch_rag`** Extbase plugin — RAG chat with cited sources,
  streaming token-by-token answer, conversation memory bounded per session.
- **Live suggest dropdown** — `/_ws_meilisearch/suggest?q=…` JSON endpoint
  + `suggest.js` widget, auto-attached to any FE input via a configurable
  CSS selector when the layout doesn't render the search-plugin template.
- **Similar documents** — `/_ws_meilisearch/similar?id=…` endpoint + Fluid
  ViewHelper `<ws:similarDocuments sourceId="…" as="…">` for "Related
  content" widgets.
- **Optional floating chat-widget bubble** — bottom-right, opens the RAG
  plugin in a slide-up panel; target page configured via `pageUid` so it
  follows the active language overlay.

**Retrieval-Augmented Generation (RAG)**

- Cited-source chat answers grounded in Meilisearch hits.
- **Provider-agnostic LLM layer**: OpenAI, Anthropic, Mistral / Scaleway,
  Ollama, Infomaniak, and generic OpenAI-compatible REST endpoints. Switch
  via `meilisearch.rag.provider`.
- **Configurable retrieval ladder**: per-RAG `matchingStrategy`,
  `stripStopWords` with per-RAG word list, `semanticRatio`, three-stage
  fallback (frequency → last → drop-leading-token) so verb-led questions
  ("Wie gebe ich …?") never collapse to `no_context`.
- **Conversation memory** per browser session, bounded so the prompt
  stays within token budget.
- **Streaming responses** via Messenger / SSE — the visitor sees the
  answer being typed, not a 30 s spinner.

**Quality assurance**

- **RAG regression tests** — editor-maintained (question, expected) pairs
  in `tx_wsmeilisearch_ragtest`, scored via embedding cosine similarity.
  Per-test threshold, rolling 100-run history, sparklines in the BE tab.
- **"Adopt actual as expected"** button — index drift across reindexes
  produces minor wording changes that the cosine scorer punishes;
  operators promote a manually-OK'd actual as the new baseline instead of
  lowering the threshold globally.
- **Stuck-task watchdog** — cancels Meilisearch tasks parked in
  `processing` past a configurable threshold and emails the operator.
- **Quota checks** for commercial AI providers (Anthropic, OpenAI,
  Infomaniak) — email warning above the configured monthly threshold.

**Operations**

- **Backend module** under *System → Meilisearch* with tabs:
  Overview · Test search & RAG · Diagnostics · Knowledge resources ·
  RAG tests · **Analytics**.
- **Analytics** tab: top queries, zero-result queries, source breakdown
  (search / suggest / similar), hybrid-vs-keyword rate, with 1/7/30/90-day
  windows. Opt-in per site; stores only aggregable signals — no IPs, no
  session ids, no user agents.
- **Throttled reindex** via `meilisearch.indexing.requestsPerMinute`
  (token bucket) when the embedding provider rate-limits per minute.
- **CLI commands**: `reindex`, `apply-settings`, `setup-index-config`,
  `doctor`, `ask`, `document`, `tika-probe`, `abort-stuck-tasks`,
  `check-quotas`, `import-knowledge-resources`, `run-rag-tests`,
  `sys-file-sweep`.

## System requirements

| Component | Version | Notes |
|---|---|---|
| **TYPO3** | `^14.0` | uses v14 PSR-7 attribute container, Site Settings typed identifiers, Locale value object |
| **PHP** | `^8.2` | readonly properties, enums, `mixed` returns |
| **Meilisearch** | `>= 1.12` | needs `/similar`, `disableOnWords`, `disableOnNumbers`, swap-indexes; v1.47+ recommended for stable embedder pipeline |
| **Apache Tika** | optional, `>= 2.x` recommended | required only for FAL text extraction (PDF / Office / RTF / EPUB) |
| **Composer deps** | `cmsig/seal ^0.12`, `cmsig/seal-meilisearch-adapter ^0.12`, `meilisearch/meilisearch-php ^1.10`, `lochmueller/index ^2.0`, `patrickschur/language-detection ^5.3` | pulled in via this package's `composer.json` |
| **Embedder** (optional) | any OpenAI-compatible `/v1/embeddings` endpoint | tested with Scaleway Generative APIs, Infomaniak AI Tools, OpenAI, Ollama, Mistral La Plateforme |
| **LLM** (optional, for RAG) | OpenAI-compatible `/v1/chat/completions` | OpenAI, Anthropic, Mistral / Scaleway, Ollama, Infomaniak |
| **Database** | MariaDB 10.5+ / MySQL 8.0+ | uses JSON columns + utf8mb4 collation; standard TYPO3 v14 baseline |
| **DDEV** (local dev) | `>= 1.22` | ships `.ddev/docker-compose.meilisearch.yaml` + `docker-compose.tika.yaml` |

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

### Index filtering

Three optional settings under `meilisearch.indexing` keep junk files
(icons, configs, backups) out of the corpus. They run at the iterator
level in `FileSchemaProvider`, so filtered files never become docs —
no wasted Tika roundtrips, faster reindex.

```yaml
meilisearch:
  indexing:
    # Whitelist — when non-empty, ONLY these extensions index. The
    # blacklist below is ignored. Recommended for new sites: explicit,
    # no surprises when an unexpected file type sneaks into fileadmin.
    allowedExtensions: [pdf, docx, doc, html, htm, md, txt, rtf, odt, epub, pptx, xlsx, ppt, xls]
    # Blacklist — applied only when allowedExtensions is empty.
    # Backward-compatible fallback for sites that already use this.
    excludeExtensions: [yaml, yml, log, bak, tmp]
    # Image size floor — drops icons / flags / decoration. Files with
    # mime starting with image/ and size < this threshold are skipped.
    # 0 (default) disables the filter; 10 KB catches most icons.
    minImageSizeKb: 10
```

The three filters compose: a file must pass the extension gate
(whitelist if set, otherwise blacklist) AND the image-size gate
before being eligible for indexing. Comparison is case-insensitive
and leading dots are stripped (`.YAML` matches `yaml`).

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

## Help-doc importers

Beyond the auto-indexed core record types (pages, news, FAL files), the
extension ships a generic **help-doc** record type (`tx_wsmeilisearch_helpdoc`,
type=`help` in the unified index) and five pluggable importers that
populate it from very different sources. The intent: a single search +
RAG corpus that can absorb a vendor's DITA documentation, an editor's
PDF upload, a fileadmin sync, a zip drop, and an external URL list —
without each source needing its own schema or controller.

All importers extend a single contract (`HelpDocSourceImporter`) and
are picked up via DI auto-tagging. Adding a sixth source means
implementing the interface — no controller / CLI / template changes.

### Built-in importers

| Slug | Source | Best for | Picker |
|---|---|---|---|
| `dita-ot` | DITA-OT XHTML drop on disk | Strukturierte help topics with TOC + per-topic media | Target media folder |
| `single-file` | One PSR-7 upload | Editor pastes a single curated PDF / DOCX / Markdown | Target folder |
| `folder` | FAL folder walk | Files dropped into fileadmin via FileList / FTP / sync | Source folder + Target folder |
| `zip-bundle` | One PSR-7 zip upload | A stack of mixed docs delivered as one archive | Target folder |
| `url-list` | HTTP fetch a list of URLs | Seeding from public docs sites / S3 PDF lists / wikis | Target folder |

Common behaviour:

- Apache **Tika** extracts body text from every supported file format
  (PDF, DOCX, HTML, RTF, EPUB, Markdown, plain text, Office, …).
  Anything outside Tika's mime allowlist still gets indexed by title
  (HTML pages additionally get a strip_tags fallback so they're
  searchable by content).
- **FAL is the file store.** Every imported file becomes a `sys_file`
  and is attached to the helpdoc row's `media` field via
  `sys_file_reference`. Search results can deep-link to the original
  file; `source_path` carries the canonical URL or path.
- **Per-importer subfolders** keep uploads separate from zip extracts
  and URL fetches inside the operator-chosen target — `uploads/`,
  `zips/`, `urls/` are auto-created beside each other under the
  target. The folders are created **segment-by-segment** so a
  first-time editor can pick `1:/whatever-i-want/` without prepping
  fileadmin.
- **Identifier scheme**: `<sanitised-filename>-f<falUid>` — stable
  across renames, unique even when two files share a basename, and
  predictable enough for downstream cross-references.

### Configuration

Two site settings drive the help-doc pipeline:

```yaml
meilisearch:
  helpdoc:
    # Static HTML corpus served at /hilfe/<path> via HelpTopicMiddleware
    # (DITA-OT XHTML output). Leave empty to disable the middleware.
    sourceRoot: 'chatbot/ChatbotHilfe/DE_xhtml'
    # Default FAL target folder for all importers. Operators override
    # per import via the Browse picker in the BE form.
    fileadminFolder: '1:/helpdocs/'
```

`tx_wsmeilisearch_helpdoc` is shipped by `ext_tables.sql` and registered
in `indexedTables` by default — running `ws_meilisearch:reindex` after
the first import pushes the rows into the unified per-site index.

### Backend workflow

The **Help docs** tab on the System → Meilisearch module gives operators
one form per importer slug:

- **Run import** (dita-ot) — source path + language directory +
  optional purge before importing.
- **Upload single document** (single-file) — file + title + abstract
  + language + document kind + target folder.
- **Batch-import from FAL folder** (folder) — source folder picker +
  recursive opt-in + language + document kind.
- **Upload ZIP bundle** (zip-bundle) — file + language + document
  kind + "preserve subfolders" toggle + target folder.
- **Import from URL list** (url-list) — textarea (one URL per line,
  `#` comments + blanks skipped) + language + document kind +
  timeout + max size + target folder.

The **Purge by language** card next to these forms hard-deletes every
helpdoc row in the chosen language with a confirm-checkbox guard.
**Reindex is not triggered automatically** — every form trailer
reminds the operator to run `ws_meilisearch:reindex` (or use the
Overview tab) afterwards.

The Browse buttons on every folder field open TYPO3's standard FAL
folder picker as a modal. The modern URL parameters
(`?fieldReference=…&useEvents=1`) are used instead of the legacy
`bparams` pipe-string, so the picker dispatches a CustomEvent on its
iframe and avoids the postMessage origin gauntlet inherent to nested
backend modals.

### CLI workflow

The dispatch CLI is `ws_meilisearch:import-help-docs`. The
`--importer=<slug>` switch picks the implementation; every other
parameter is interpreted via the importer's `describeFields()` schema.

```bash
# See every registered importer and its accepted fields
ddev exec vendor/bin/typo3 ws_meilisearch:import-help-docs --list-importers

# DITA-OT XHTML drop (shorthand options for the well-known fields)
ddev exec vendor/bin/typo3 ws_meilisearch:import-help-docs \
  --importer=dita-ot \
  --path=path/to/dita-out \
  --langDir=de \
  --language=0 \
  --no-purge

# Single file upload — best driven via the BE form (CLI uploads need
# a PSR-7 UploadedFileInterface)

# Walk a FAL folder
ddev exec vendor/bin/typo3 ws_meilisearch:import-help-docs \
  --importer=folder \
  -f folder=1:/handbooks/ \
  -f recursive=1 \
  -f language=0 \
  -f help_type=reference

# URL list (one per line, # comments + blanks skipped)
ddev exec vendor/bin/typo3 ws_meilisearch:import-help-docs \
  --importer=url-list \
  -f urls=$'https://example.com/handbook.pdf\nhttps://example.com/policy.html' \
  -f targetFolder=1:/external-docs/ \
  -f timeout=30 \
  -f maxSizeMb=50

# ZIP bundle — same caveat as single-file (PSR-7 upload only)
```

The CLI prints a progress bar per item, lists every per-item failure
verbatim (Tika skip, HTTP error, FAL consistency rejection, …), and
returns the `imported / skipped / mediaCopied` triple in the final
success line. Generic `-f name=value` pairs always override the
shorthand options.

### Safety notes per importer

- **`url-list`** does NOT enforce a domain allowlist. BE-only access
  is the trust boundary; do not expose the form to anonymous users.
  Only `http`/`https` schemes are accepted; size cap (default 50 MB)
  and per-URL timeout (default 30 s) prevent slow servers / oversized
  responses from wedging the batch.
- **`zip-bundle`** rejects entries containing `..`, leading `/`, or
  null bytes (zip-slip), caps at 1000 entries (zip-bomb guard), and
  silently skips dotfiles (`.DS_Store`, `__MACOSX/`, …).
- **MIME / extension mismatch.** TYPO3 v14's `ResourceConsistencyService`
  rejects files whose actual content (per `finfo`) disagrees with
  the URL-derived extension. The url-list importer runs `finfo` on
  the response body and picks the matching extension so DITA-OT
  XHTML pages (which declare `<?xml version="1.0"?>` and get
  classified as `text/xml`) land as `.xml` instead of `.html`.

### Adding a custom importer

Implement `HelpDocSourceImporter` in your extension's `Classes/Service/Import/Importer/`:

```php
final class ConfluenceExportImporter implements HelpDocSourceImporter
{
    public function name(): string { return 'confluence-export'; }
    public function label(): string { return 'Confluence space export'; }
    public function description(): string { return 'Walk an exported Confluence space.'; }
    public function describeFields(): array
    {
        return [
            ['name' => 'exportPath', 'label' => 'Export path', 'type' => 'text', 'required' => true],
            ['name' => 'language', 'label' => 'Language', 'type' => 'language', 'default' => 0],
            ['name' => 'targetFolder', 'label' => 'Target folder', 'type' => 'folder'],
        ];
    }
    public function import(array $config, ?callable $onProgress = null): ImportResult { ... }
}
```

The `_instanceof` rule in `Configuration/Services.yaml` auto-tags it
as `ws_meilisearch.source_importer`, so it appears in both
`--list-importers` and the BE Help-docs tab without further wiring.
Use the injected `HelpDocRepository` for FAL + Tika + persistence —
the helpers handle target-folder auto-creation, sanitisation, and
the standard `media` reference attachment.

## RAG quality regression

Editor-maintained (question, expected answer, threshold) triples live
in `tx_wsmeilisearch_ragtest`. The runner asks the configured RAG
provider each question, embeds expected + actual via the site's
embedder, and scores cosine similarity against the per-row
`similarity_threshold` — pass / fail / error. Idempotent and safe
to run on cron; the same engine is reachable from three places so
ad-hoc triage and unattended runs never drift.

### Three trigger paths, one engine

| Trigger | When to use |
|---|---|
| **BE tab** "RAG tests" | Ad-hoc triage. Per-row Run button + global Run-all. Sparkline column shows the last ~30 score points so trends are visible at a glance. |
| **CLI** `ws_meilisearch:run-rag-tests [site] [--show-answers]` | One-shot from a deploy script or local checking. Distinct exit codes (0 / 1 / 2) for cron — see "Exit-code taxonomy" below. |
| **Scheduler task** *Meilisearch: RAG regression tests* | Periodic monitoring. TYPO3-native v14 task; reuses `tx_wsmeilisearch_site_identifier`. Returns `false` on any FAIL so the scheduler flags the run; ERROR-only runs stay `true` (infrastructure hiccup, not regression). |

### Threshold-tuning is per-test

Cosine similarity scores depend heavily on the embedder and on text
length. 0.85 is a sane default for `nomic-embed-text` on full-paragraph
expected answers; short German texts often score 0.80+ even on
semantically-unrelated content because of shared vocabulary. The
operator picks the threshold per row based on how strict the match
needs to be:

- `0.70` → permissive, catches paraphrases but also tolerates "no
   information" replies
- `0.85` → strict semantic match
- `0.95` → near-verbatim agreement

### Embedding clients

`HelpDocSourceImporter`-style plugin pattern. The right client is
picked by matching `meilisearch.embedder.source` against each
registered client's `supports()` vote:

| Source slug | Endpoint |
|---|---|
| `ollama` | Native `/api/embeddings` (not the OpenAI-compatible `/v1/...` route — they share a host but expect different request shapes) |
| `openAi` | `/v1/embeddings` with bearer token; default URL `https://api.openai.com/v1/embeddings` |
| `infomaniak` | `/1/ai/<productId>/openai/v1/embeddings` — URL built from `meilisearch.infomaniak.productId`; same key as RAG / Meilisearch embedder |

Add another provider by implementing `EmbeddingClientInterface`; the
`_instanceof` rule in `Services.yaml` auto-tags it and the registry
picks it up.

### Per-run history + sparklines

Every run also writes a row to `tx_wsmeilisearch_ragtest_run` (test
uid, status, score, actual answer, crdate). A rolling per-test
prune keeps the table at `RagTestRunner::HISTORY_KEEP=100` rows so
growth is bounded without operator cron. The BE tab pre-renders an
inline SVG sparkline of the last 30 scores per test — Y axis is
fixed 0..1 so two sparklines compare visually across tests, and
the `<title>` carries `count / min / max / last` for hover detail.

### Exit-code taxonomy (CLI + scheduler)

| Exit | Meaning |
|---|---|
| `0` | All PASS |
| `1` | At least one FAIL — real quality regression. Cron monitor latches. |
| `2` | Errors only (RAG provider down, embedder down, transport hiccup). NOT a quality signal — re-run after the underlying fix. |

Same distinction maps to the scheduler task return value: `false` only
when a FAIL happened; ERROR-only runs stay `true` so the scheduler
doesn't latch on transient infrastructure noise.

## Quota checks for commercial providers

`ws_meilisearch:check-quotas` walks every site, fans out to a
`QuotaProvider` per configured commercial backend (Infomaniak /
OpenAI / Anthropic), and emails a warning when usage crosses
`meilisearch.quota.threshold` (default 80%). Idempotent — only emails
when over threshold. Exit `1` when any provider is over, so cron
monitors latch.

### Configuration

```yaml
meilisearch:
  quota:
    threshold: 80                       # percent
    recipient: 'ops@example.com'         # single or comma-separated list

    # OpenAI's /v1/organization/usage/completions needs an admin key
    # (sk-admin-...), DIFFERENT from meilisearch.rag.apiKey which is
    # least-privilege completion-only.
    openai:
      adminKey: '%env(OPENAI_ADMIN_KEY)%'
      monthlyCap: 5000000               # operator-set; OpenAI returns
                                         # usage but no quota number

    # Same shape for Anthropic — admin key needed, monthly cap
    # operator-set.
    anthropic:
      adminKey: '%env(ANTHROPIC_ADMIN_KEY)%'
      monthlyCap: 10000000

    # Infomaniak's AI completion key only authorises /chat + /embeddings.
    # A Manager-scope Personal Access Token (manager.infomaniak.com →
    # API) confirms the AI product is reachable; the actual usage
    # numbers must be read in the Manager UI — see the limitation note
    # below.
    infomaniak:
      apiToken: '%env(INFOMANIAK_MANAGER_TOKEN)%'
```

**Infomaniak limitation:** Verified 2026-06-07 against Infomaniak's
production API with a Manager-scope token: there is currently NO
usage endpoint for AI Tools. `/1/ai` returns product reachability
+ status but no token counts; product-scoped paths
(`/1/ai/<id>/usage`, `/quota`, `/spending`, …) all return 404. The
Infomaniak provider does what it can — confirm reachability + point
the operator at `manager.infomaniak.com/v3/ai/products/<id>/usage`
for manual gauge reading. Until Infomaniak exposes an API the
"current state" badge stays ERROR with that explanatory message
rather than faking a green light.

### Adding a custom provider

Implement `QuotaProviderInterface`, return `QuotaStatus::ok(...)` /
`::error(...)`. The `_instanceof` tag auto-registers it; the runner
dispatches by matching the site's configured provider slug.

```php
final class MyProvider implements QuotaProviderInterface
{
    public function name(): string { return 'My provider'; }
    public function supports(string $slug): bool { return $slug === 'myco'; }
    public function checkQuota(Site $site): QuotaStatus { /* … */ }
}
```

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
# Indexing (news + sys_file; pages flow through Integration/ExtIndex — see below)
ddev exec vendor/bin/typo3 ws_meilisearch:reindex                        # all sites
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main                    # one site, incremental
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild          # drop + recreate first
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --skip-embedder    # leave embedder config untouched

# Page indexing via Integration/ExtIndex (on top of EXT:index)
ddev exec vendor/bin/typo3 ws_meilisearch:setup-index-config main         # create/repair the EXT:index Configuration row
ddev exec vendor/bin/typo3 index:queue --limitSiteIdentifiers=main        # seed the message queue
ddev exec vendor/bin/typo3 messenger:consume index --limit=500            # drain the queue (bridge writes to Meilisearch)

# Diagnostics
ddev exec vendor/bin/typo3 ws_meilisearch:doctor                          # health-check all sites
ddev exec vendor/bin/typo3 ws_meilisearch:doctor main                     # one site
ddev exec vendor/bin/typo3 ws_meilisearch:document pages-42 main          # inspect one document
ddev exec vendor/bin/typo3 ws_meilisearch:tika-probe 1:/some.pdf main     # run a file through Tika

# RAG (Phase 4) — runs the configured LLM provider against the site index
ddev exec vendor/bin/typo3 ws_meilisearch:ask "How do I reset my password?" main

# Help-doc importers — five built-in source formats
ddev exec vendor/bin/typo3 ws_meilisearch:import-help-docs --list-importers
ddev exec vendor/bin/typo3 ws_meilisearch:import-help-docs --importer=folder -f folder=1:/handbooks/ -f recursive=1
ddev exec vendor/bin/typo3 ws_meilisearch:import-help-docs --importer=url-list -f urls=$'https://example.com/policy.pdf'

# RAG quality regression — score actual answers against expected ones
ddev exec vendor/bin/typo3 ws_meilisearch:run-rag-tests                    # all enabled tests, all sites
ddev exec vendor/bin/typo3 ws_meilisearch:run-rag-tests main               # one site only
ddev exec vendor/bin/typo3 ws_meilisearch:run-rag-tests --show-answers     # verbose: print actual answers per test

# Commercial AI provider quota check + threshold-based warning email
ddev exec vendor/bin/typo3 ws_meilisearch:check-quotas                     # all sites, mail on over-threshold
ddev exec vendor/bin/typo3 ws_meilisearch:check-quotas main --dry-run      # one site, print table, no mail
```

## What's wired

| Layer | Component | File |
|---|---|---|
| Plugin registration | Extbase plugin `WsMeilisearch / Search` (CType `wsmeilisearch_search`) | `ext_localconf.php`, `Configuration/TCA/Overrides/tt_content.php` |
| Site Set | `wapplersystems/ws-meilisearch` with typed settings + TypoScript | `Configuration/Sets/WsMeilisearch/*` |
| Indexing extension point | `SchemaProviderInterface` (auto-tagged via `_instanceof`) | `Classes/Domain/Schema/` |
| Default providers | tx_news (gated on EXT:news) + sys_file (one doc per site language with sys_file_metadata overlay). Pages are indexed via the bundled `Integration/ExtIndex` on top of `lochmueller/index`. | `NewsSchemaProvider.php`, `FileSchemaProvider.php`, `Classes/Integration/ExtIndex/EventListener/IndexEventListener.php` |
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
| Diagnostics CLI | `ws_meilisearch:doctor` / `:setup-index-config` / `:document` / `:tika-probe` for operator triage | `Classes/Command/DoctorCommand.php`, `SetupIndexConfigCommand.php`, `DocumentCommand.php`, `TikaProbeCommand.php` |
| Backend module | System → Meilisearch: per-site index status, Reindex / Rebuild buttons, ad-hoc Search + RAG test forms, Help-doc importer dashboard | `Classes/Controller/Backend/OverviewController.php` |
| Help-doc importers | Plugin architecture for populating `tx_wsmeilisearch_helpdoc` from DITA-OT drops, single uploads, FAL folders, ZIP bundles, URL lists | `Classes/Service/Import/HelpDocSourceImporter.php`, `Classes/Service/Import/Importer/*` |
| Help-doc CLI | `ws_meilisearch:import-help-docs --importer=<slug>` dispatcher with per-importer field schema | `Classes/Command/ImportHelpDocsCommand.php` |
| FAL folder picker | `data-wsm-folder-picker` button opens TYPO3's standard element-browser modal; writes the combined identifier back into the bound input via the picker's CustomEvent | `Resources/Public/JavaScript/folder-picker.js`, `Configuration/JavaScriptModules.php` |
| RAG regression runner | Run one or many (question, expected, threshold) rows, embed via the configured `EmbeddingClient`, score cosine, persist per-test state + rolling history. Shared by CLI, scheduler, BE tab. | `Classes/Service/RagTest/RagTestRunner.php`, `Classes/Service/RagTest/EmbeddingClient*.php` |
| RAG regression CLI | `ws_meilisearch:run-rag-tests [site] [--show-answers]` with pass / fail / error exit codes for cron | `Classes/Command/RunRagTestsCommand.php` |
| RAG regression scheduler task | TYPO3 v14 native task — same engine + return value `false` on FAIL, `true` on ERROR-only | `Classes/Task/RunRagTestsTask.php` |
| RAG regression BE tab | `?action=ragtests`: per-test state table with sparklines, Run-now / Run-all, summary badges, New-test deep link | `Classes/Controller/Backend/RagTestController.php`, `Resources/Private/Templates/Backend/Overview/RagTests.html` |
| Quota check CLI | `ws_meilisearch:check-quotas [site] [--dry-run]` — fans out to `QuotaProvider` per configured commercial backend, emails over-threshold | `Classes/Command/CheckQuotasCommand.php`, `Classes/Service/Quota/*` |
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
| [12](Examples/12-tika-ocr.md) | OCR for scanned PDFs + images |
| [13](Examples/13-sort-pagination.md) | Sort dropdown + pagination in the FE plugin |

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

### Per-instance overrides via FlexForm

The Search plugin ships a FlexForm so the same `wsmeilisearch_search`
CType can be configured differently per content element. Every field
is optional — empty inherits from the Site Settings default.

| FlexForm field | Overrides | Notes |
|---|---|---|
| Visible facets | `meilisearch.facets` | Comma-separated attribute list (e.g. `type,language`) |
| Results per page | `meilisearch.frontend.perPage` | Int 0..500. 0 inherits the site default |
| Default sort | (none — initial sort) | One of: Relevance, datetime desc/asc, fileSize desc/asc. Visitor's `?sort=` param still wins |
| Restrict to current language | `meilisearch.restrictToCurrentLanguage` | Tri-state: Inherit / Force ON / Force OFF |

Useful when a per-language search page wants the language filter
forced on while the global search page wants cross-language results
— same install, same site settings, different plugin instances.

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

Done:

- Basic indexing + Fluid plugin with typo tolerance & facets
- FAL / Tika indexing (PDF / Office / RTF / EPUB / plain text)
- Hybrid search + auto-embeddings (OpenAI / HuggingFace / Ollama / REST / userProvided / Scaleway / Infomaniak presets)
- PHP-precomputed embeddings (`meilisearch.embedder.precompute`) with token-bucket throttle against rate-limited providers
- RAG module with configurable LLM provider (OpenAI / Anthropic / Mistral / Scaleway / Ollama / Infomaniak / REST)
- Backend module (Overview, Diagnostics, Test, Knowledge resources, RAG tests, Analytics) + scheduler tasks
- Knowledge-resource importers (DITA-OT / single-file / FAL folder / ZIP bundle / URL list) with shared plugin contract
- RAG regression tests with cosine-similarity scoring, BE tab + sparklines, **adopt-actual-as-expected** baseline promotion
- Commercial-provider quota checks with email warnings
- Content-language detection (n-gram, ISO 639-1) + content-language filter
- Live suggestions endpoint + JS dropdown with optional auto-attach
- Similar documents endpoint, middleware, Fluid ViewHelper
- Zero-downtime reindex via atomic index swap
- Search analytics (top / zero-result / source breakdown / hybrid rate)
- Stuck-task watchdog

Open / under consideration:

- Search-analytics retention cleanup task (currently rows accumulate indefinitely; manual `DELETE` works)
- Click-tracking + CTR per query (analytics rows currently cover query-side only)
- Layout-level search-form auto-attach with shipped CSS (selector setting is in place, no default selector yet)
- Locales (per-field language tokenizer hint) — Meilisearch 1.13+
- Index swap probe job to verify swap pipeline end-to-end before first production use

## Limitations

**Hybrid / embedder**

- Meilisearch's `vectorStore` experimental feature must be enabled (one
  PATCH on `/experimental-features`). Sending `embedders` settings to a
  server with the feature off returns a 400 and aborts the reindex.
- `userProvided` embedder requires every document to ship its own
  vector in `_vectors.default`. The `precompute` mode handles this
  automatically; if you turn precompute off and select `userProvided`
  directly, the schema providers won't fill the vector field.
- API-key rotation isn't auto-detected — Meilisearch redacts the key
  on read-back, so the configurator can't diff "new" vs "redacted" to
  decide whether to PATCH. Touch any other embedder setting (or run
  `--rebuild`) to force a re-push after key rotation.
- Hybrid result hits skip the SEAL adapter — frontend code that
  inspects fields beyond the unified schema may see slightly different
  shapes between keyword and hybrid results.

**RAG**

- Streaming requires unbuffered hosting. The
  `/_ws_meilisearch/rag/stream` SSE endpoint works in DDEV out of the
  box, but production behind Nginx needs `proxy_buffering off` /
  `fastcgi_buffering off` on that path.
- Conversation memory is opt-in via
  `meilisearch.rag.conversation.enabled = true`. Default is stateless.
- Citation extraction is regex-based — models that wrap markers in
  prose ("see [id=foo and id=bar]") only get the first id captured.
  Tune the system prompt per model.
- No token budgeting on `maxContextHits` × `maxContextChars`; a very
  long question + many large hits can blow past small-model context
  windows.
- No cost / rate-limit guard on the FE — pair with a
  `BeforeLlmCallEvent` listener (response cache, per-session rate
  limit) for production deployments.

**Indexing**

- DataHandler hooks during a zero-downtime reindex write to the live
  primary; the swap then overwrites those updates. Editorial changes
  made during a multi-hour reindex may need a follow-up record-level
  reindex.

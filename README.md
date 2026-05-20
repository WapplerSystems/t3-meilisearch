# ws_meilisearch — Meilisearch Search Backend for TYPO3

TYPO3 v14 extension providing Meilisearch-backed full-text search via the
[SEAL](https://github.com/php-cmsig/search) abstraction. Designed so the
search backend stays swappable (Meilisearch today, Typesense / Elasticsearch
tomorrow) without rewriting templates or services.

## Status

**Phase 1 + 2 + 3 — wired and working.** Pages, news, and FAL files
(PDF / Office / RTF / EPUB / plain text via Apache Tika) all share a
single unified per-site index, faceted by `type`. Frontend plugin
renders GET forms with typo-tolerant search + click-to-filter facets.
Hybrid (keyword + semantic vector) search is available when an embedder
is configured.

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
  tika:
    url: 'http://tika:9998'
    timeout: 60
    maxFileSize: 52428800
```

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

## CLI

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:reindex                        # all sites
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main                    # one site, incremental
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild          # drop + recreate first
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --skip-embedder    # leave embedder config untouched
```

## What's wired

| Layer | Component | File |
|---|---|---|
| Plugin registration | Extbase plugin `WsMeilisearch / Search` (CType `wsmeilisearch_search`) | `ext_localconf.php`, `Configuration/TCA/Overrides/tt_content.php` |
| Site Set | `wapplersystems/ws-meilisearch` with typed settings + TypoScript | `Configuration/Sets/WsMeilisearch/*` |
| Indexing extension point | `SchemaProviderInterface` (auto-tagged via `_instanceof`) | `Classes/Domain/Schema/` |
| Default providers | Pages + tx_news (gated on EXT:news) + sys_file | `PageSchemaProvider.php`, `NewsSchemaProvider.php`, `FileSchemaProvider.php` |
| Engine factory | Reads site settings, builds unified SEAL Engine + Index | `Classes/Service/SearchEngineFactory.php` |
| Indexer | Iterates providers, dispatches lifecycle events, waits on Meilisearch async tasks | `Classes/Service/IndexerService.php` |
| Search service | Builds SEAL query (search + filters + facets), maps result; hybrid path bypasses SEAL to use Meilisearch SDK directly | `Classes/Service/SearchService.php` |
| Tika integration | Apache Tika REST client + sha1-keyed cache | `Classes/Service/Tika/` |
| Embedder configurator | Idempotent PATCH of per-index embedder settings, source-aware field allowlist, waits for async settingsUpdate | `Classes/Service/EmbedderConfigurator.php` |
| Realtime sync | DataHandler hook → indexer (sys_file_metadata translated to sys_file) | `Classes/DataHandling/RecordChangeListener.php` |
| CLI | `ws_meilisearch:reindex [site] [--rebuild]` | `Classes/Command/ReindexCommand.php` |
| Events (PSR-14) | Before/After Document Indexed, Before/After Search | `Classes/Event/` |
| Frontend templates | GET-only forms, auto-submit facets, PRG-redirect on stray POSTs | `Resources/Private/Templates/Search/` |

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
- **Phase 4** — RAG module with configurable LLM provider
- **Phase 5** — Backend module, scheduler tasks, multi-site/language polish

## Known Phase 2 limitations

- **No per-language metadata** — sys_file_metadata is translatable, but the
  indexer reads default-language only. Multi-language overlays are a
  Phase 2.1 task.
- **No FAL lifecycle events** — DataHandler covers metadata edits, but FAL
  file uploads/deletions/moves go through other channels. Rely on
  `ws_meilisearch:reindex` for full coverage until FAL events are wired.
- **No OCR** — `apache/tika:3.0.0.0` (base image, ~500 MB) ships without
  Tesseract. Swap to `apache/tika:3.0.0.0-full` (~1.5 GB) for OCR on
  scanned PDFs / images. Phase 3 will revisit.
- **Files indexed per-site** — same file gets indexed into every
  Meilisearch-configured site's index. Deduplication via
  sys_file_reference → page → site is a Phase 2.1 task.
- **No file result link in the FE template** — file hits show the title
  but don't link to the public URL yet.

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

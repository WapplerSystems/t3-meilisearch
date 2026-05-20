# ws_meilisearch — Meilisearch Search Backend for TYPO3

TYPO3 v14 extension providing Meilisearch-backed full-text search via the
[SEAL](https://github.com/php-cmsig/search) abstraction. Designed so the
search backend stays swappable (Meilisearch today, Typesense / Elasticsearch
tomorrow) without rewriting templates or services.

## Status

**Phase 1 — Skeleton.** All wiring is in place (DI, plugin registration,
Site Set, DataHandler listener, CLI command, Fluid templates, PSR-14 events),
but the actual SEAL Engine calls are stubbed with `TODO` markers. The next
step is `composer require` followed by replacing those stubs.

## Installation

The extension lives as a local package in `packages/wapplersystems/meilisearch/`,
already picked up by the root `composer.json`. To install:

```bash
ddev composer require wapplersystems/meilisearch:@dev
```

This pulls in the SEAL deps:

- `cmsig/seal` — engine + schema abstraction
- `cmsig/seal-meilisearch-adapter` — Meilisearch backend
- `lochmueller/seal` — TYPO3 integration glue
- `meilisearch/meilisearch-php` — official PHP SDK

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
```

Definitions live in `Configuration/Sets/WsMeilisearch/settings.definitions.yaml`
so they are typed and editable through the Backend Sites module.

## What's wired in Phase 1

| Layer | Component | File |
|---|---|---|
| Plugin registration | Extbase plugin `WsMeilisearch / Search` | `ext_localconf.php`, `Configuration/TCA/Overrides/tt_content.php` |
| Site Set | `wapplersystems/ws-meilisearch` with typed settings + TypoScript | `Configuration/Sets/WsMeilisearch/*` |
| Indexing extension point | `SchemaProviderInterface` (tagged service `ws_meilisearch.schema_provider`) | `Classes/Domain/Schema/` |
| Default providers | Pages + tx_news (gated on EXT:news being loaded) | `PageSchemaProvider.php`, `NewsSchemaProvider.php` |
| Engine factory | Reads site settings, builds SEAL Engine | `Classes/Service/SearchEngineFactory.php` |
| Indexer | Iterates providers, dispatches lifecycle events | `Classes/Service/IndexerService.php` |
| Search service | FE wrapper around SEAL | `Classes/Service/SearchService.php` |
| Realtime sync | DataHandler hook → indexer | `Classes/DataHandling/RecordChangeListener.php` |
| CLI | `vendor/bin/typo3 ws_meilisearch:reindex [site]` | `Classes/Command/ReindexCommand.php` |
| Events (PSR-14) | Before/After Document Indexed, Before/After Search | `Classes/Event/` |
| Templates | Search form + faceted results | `Resources/Private/Templates/Search/` |

## What's stubbed (TODO before Phase 1 ships)

Search for `TODO` in `Classes/Service/`:

1. `SearchEngineFactory::createForSite()` — return a real `CmsIg\Seal\Engine`
   built from `MeilisearchAdapter` + the combined schema of all providers.
2. `IndexerService::indexProvider/indexRecord/removeRecord` — call
   `$engine->saveDocument()` / `deleteDocument()` with the SEAL Index name.
3. Each `SchemaProvider::getSchema()` — return a `CmsIg\Seal\Schema\Schema`
   describing searchable/filterable/sortable fields.
4. `SearchService::search()` — build a SEAL search query, map hits + facets
   into the existing `SearchResult` DTO.

## Adding a new record type

Implement `SchemaProviderInterface`. The class is auto-wired and auto-tagged
via `_instanceof` in `Configuration/Services.yaml`, no manual registration
needed.

```php
final class ProductSchemaProvider implements SchemaProviderInterface { ... }
```

## Roadmap (from BRIEFING.md)

- **Phase 1** ← *current* — basic indexing + Fluid plugin with typo tolerance & facets
- **Phase 2** — FAL/Tika indexing (PDF/Office)
- **Phase 3** — Hybrid search + auto-embeddings (OpenAI/HF/Ollama)
- **Phase 4** — RAG module with configurable LLM provider
- **Phase 5** — Backend module, scheduler tasks, multi-site/language polish
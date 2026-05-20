# 01 — Minimal keyword search

The smallest working setup. Pages and tx_news records get indexed,
the FE plugin returns typo-tolerant results with facets — no
embeddings, no LLM, no Tika.

## Stack

- Meilisearch server (Docker)
- `ws_meilisearch` extension installed via composer
- Any TYPO3 site with pages

## DDEV service

`.ddev/docker-compose.meilisearch.yaml`:

```yaml
services:
  meilisearch:
    image: getmeili/meilisearch:v1.11
    container_name: ddev-${DDEV_SITENAME}-meilisearch
    environment:
      MEILI_MASTER_KEY: 'dev_master_key'
      MEILI_ENV: 'development'
    expose:
      - "7700"
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
```

Restart DDEV, then verify: `ddev exec curl -s http://meilisearch:7700/health` returns `{"status":"available"}`.

## Site setting

`config/sites/main/settings.yaml`:

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'
```

Site set in `config/sites/main/config.yaml`:

```yaml
dependencies:
  - wapplersystems/ws-meilisearch
```

## Build the index

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild
```

Output:

```
Site: main
  → 33 documents indexed
```

## Verify

Direct Meilisearch query:

```bash
ddev exec curl -s -X POST \
  -H 'Authorization: Bearer dev_master_key' \
  -H 'Content-Type: application/json' \
  -d '{"q":"home","limit":3}' \
  http://meilisearch:7700/indexes/site1_search/search
```

…returns hits with title / type / language fields.

## Frontend

Drop the **WsMeilisearch / Search** content element on a page, point
its action URL at itself. The bundled GET-only form auto-submits to
the same page; facet checkboxes auto-submit on change.

URL after a search:

```
/search?tx_wsmeilisearch_search[action]=results
       &tx_wsmeilisearch_search[controller]=Search
       &tx_wsmeilisearch_search[q]=saskatchewan
```

The `^tx_wsmeilisearch_search` prefix is excluded from cHash by the
extension itself, so the URL is valid without a hash.

## When to use this baseline

Internal sites where you want typo-tolerant, faceted search without
any AI in the loop. Latency is dominated by the Meilisearch query
itself (single-digit ms for indexes under 10k docs).

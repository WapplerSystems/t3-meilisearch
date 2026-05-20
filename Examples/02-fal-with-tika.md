# 02 — FAL indexing with Apache Tika

Pick up where [01](01-minimal-keyword-search.md) left off. Add a Tika
service, switch on FAL file extraction, and start returning PDF /
Office / EPUB / plain-text hits.

## Stack delta

- Add `apache/tika:3.0.0.0` (base, ~500 MB) or `:3.0.0.0-full` (~1.5 GB, OCR-capable)

`.ddev/docker-compose.tika.yaml`:

```yaml
services:
  tika:
    image: apache/tika:3.0.0.0
    container_name: ddev-${DDEV_SITENAME}-tika
    expose:
      - "9998"
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
```

Verify: `ddev exec curl -s http://tika:9998/version` returns
`Apache Tika 3.0.0`.

## Site setting

`config/sites/main/settings.yaml`:

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'
  tika:
    url: 'http://tika:9998'
    timeout: 60              # seconds; scanned PDFs can be slow
    maxFileSize: 52428800    # 50 MB; bigger files are skipped without contacting Tika
  # Optional: only index files actually referenced from pages of this site.
  # Default false = every site indexes every non-missing sys_file row.
  deduplicateFiles: false
```

## Index

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild
```

Document count includes one `type=file` doc per (file, site language).

## Verify

```bash
# All file hits in the index
ddev exec curl -s -X POST \
  -H 'Authorization: Bearer dev_master_key' \
  -H 'Content-Type: application/json' \
  -d '{"q":"","filter":"type = file","limit":5}' \
  http://meilisearch:7700/indexes/site1_search/search
```

Full-text search inside a PDF:

```bash
ddev exec curl -s -X POST \
  -H 'Authorization: Bearer dev_master_key' \
  -H 'Content-Type: application/json' \
  -d '{"q":"saskatchewan","limit":3}' \
  http://meilisearch:7700/indexes/site1_search/search
```

## Per-site dedup

When `deduplicateFiles: true`, FileSchemaProvider builds a single map
`[siteId => {fileUid => true}]` from `sys_file_reference.pid → site`
and only emits docs for files that are referenced from a page of the
current site.

Trade-off: a freshly uploaded file with no `sys_file_reference` row
yet won't show up until something includes it. Default behavior
(every site indexes every file) treats the index as a global file
library; the dedup behavior treats it as strict per-site search.

## Multi-language FAL metadata

When a site has multiple `languages:` entries, FileSchemaProvider
emits one document per (file, language) pair, overlaying the
`sys_file_metadata` row that matches the language (`l10n_parent`
overlay). Body text comes from Tika (language-agnostic) so all
language variants share the same extracted content.

Document IDs:

- `file-{uid}` — default language (backward compatible)
- `file-{uid}-l{X}` — language X > 0

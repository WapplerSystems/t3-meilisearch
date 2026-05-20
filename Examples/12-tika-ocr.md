# 12 — OCR for scanned PDFs and images

The base `apache/tika:3.0.0.0` image extracts text from PDFs that
already have an embedded text layer — but it returns nothing for
scanned PDFs (just pixels) or for image files. This example swaps in
the `-full` tag (Tesseract bundled) and turns on OCR so the index
picks up those documents too.

Builds on [02](02-fal-with-tika.md).

## Stack delta

Replace the base Tika image with the OCR-capable variant. About 1
GB extra to pull, ~150 MB more RAM at runtime.

`.ddev/docker-compose.tika.yaml`:

```yaml
services:
  tika:
    image: apache/tika:3.0.0.0-full
    container_name: ddev-${DDEV_SITENAME}-tika
    expose:
      - "9998"
    environment:
      # Tesseract needs more heap for OCR than the base image does.
      JAVA_OPTS: '-Xmx2g'
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}
```

`ddev restart` pulls the new image. Verify Tesseract is present:

```bash
ddev exec curl -s http://tika:9998/version
# Apache Tika 3.0.0
# (and inside the container: tesseract --list-langs shows the bundled languages)
```

## Site setting

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'
  tika:
    url: 'http://tika:9998'
    timeout: 180                     # raise — OCR is ~10-30x slower than text extraction
    maxFileSize: 104857600           # 100 MB
    ocrEnabled: true                 # opt-in; the base image rejects OCR headers with HTTP 400
    ocrLanguage: 'eng+deu'           # tesseract codes, comma-separated
    ocrStrategy: 'auto'              # auto = OCR only when the embedded text layer is empty
    allowedMimeTypes:
      # Default PDF + Office types …
      - 'application/pdf'
      - 'application/msword'
      - 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
      # … plus the image types you want OCR'd:
      - 'image/jpeg'
      - 'image/png'
      - 'image/tiff'
```

The image MIME types aren't in the default allowlist — most sites
have dozens of decorative photos that would explode the index for no
benefit. Add only the formats you actually want indexed.

## OCR strategy choices

| Strategy | What it does | When to pick it |
|---|---|---|
| `no_ocr` | Trust the embedded text layer, never OCR. | OCR-capable Tika running, but you want it off for *this* site (e.g. share a Tika pool, dedup costs to opt-in sites only). |
| `auto` (default) | Read the embedded text; if it looks empty, fall back to OCR. | Mixed corpora — modern PDFs with text + legacy scans. The fastest "do the right thing" setting. |
| `ocr_and_text_extraction` | Always OCR *and* extract the text layer, concatenate. | Documents where embedded text is unreliable (signed PDFs with overlay images, mixed scans + annotations). |
| `ocr_only` | Ignore the embedded text layer, OCR the rendered page. | When you know the embedded text is garbage (e.g. malformed encoding from old scanners). |

## Re-indexing existing content

The cache key includes the OCR-relevant settings (`ocrEnabled`,
`ocrLanguage`, `ocrStrategy`), so flipping `ocrEnabled` from false to
true automatically misses the old cache and re-extracts. No manual
cache flush needed; just:

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild
```

(`--rebuild` is technically optional since `indexAll` re-indexes
every doc anyway, but rebuild drops the index first which guarantees
a clean slate.)

## Performance trade-offs

| Operation | Base Tika | Full Tika, OCR off | Full Tika, OCR on |
|---|---|---|---|
| Plain PDF (10 pages, text layer) | ~100 ms | ~120 ms | ~120 ms (text layer wins) |
| Scanned PDF (10 pages) | 0 ms (returns empty) | 0 ms (returns empty) | ~30-60 s |
| JPEG image (typical) | rejected | rejected | ~2-5 s |
| Container RAM (idle) | ~250 MB | ~400 MB | ~400 MB |
| Container RAM (peak under OCR load) | n/a | n/a | ~1.5-2 GB |

Bottom line: OCR is the right call when the corpus actually contains
scans, otherwise pay nothing for the option. The full image is fine
even with `ocrEnabled: false` — extra megabytes on disk, identical
behavior at runtime.

## Verify

```bash
# Plant a scanned PDF in fileadmin/, reindex, then:
ddev exec curl -s -X POST \
  -H 'Authorization: Bearer dev_master_key' \
  -H 'Content-Type: application/json' \
  -d '{"q":"<word-only-visible-in-the-scan>","limit":3}' \
  http://meilisearch:7700/indexes/site1_search/search
```

If the search returns the scan as a hit, OCR is working end-to-end.

## Failure modes

- **HTTP 400 from Tika when reindexing** — `ocrEnabled: true` set
  but base image still running. Switch to `-full` or set
  `ocrEnabled: false`.
- **Tika timeout on every PDF** — bump `timeout` to 300+, or lower
  `maxFileSize` so the giant ones get skipped.
- **OCR text looks garbled** — wrong language code. `tesseract
  --list-langs` inside the container shows what's installed; usually
  `eng`, `deu`, `fra`, `spa`, `osd`. For non-Western scripts on the
  full image, add extra Tesseract language packs via your own
  Dockerfile derived from `apache/tika:*-full`.

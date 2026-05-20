# 04 — Hybrid search with self-hosted Ollama

Same goal as [03](03-hybrid-openai.md) but the embedder runs locally
in your DDEV stack. Zero per-query cost, no API key, no third-party
data transit.

Trade-off: indexing throughput is bound by CPU/GPU; for production
expect 5–20 docs/sec on a modest server (vs. 100+ docs/sec to OpenAI).

## Stack delta

`.ddev/docker-compose.ollama.yaml`:

```yaml
services:
  ollama:
    image: ollama/ollama:0.4
    container_name: ddev-${DDEV_SITENAME}-ollama
    expose:
      - "11434"
    volumes:
      - ollama-models:/root/.ollama
    labels:
      com.ddev.site-name: ${DDEV_SITENAME}
      com.ddev.approot: ${DDEV_APPROOT}

volumes:
  ollama-models:
```

After `ddev restart`, pull a small embedding model:

```bash
ddev exec --service web curl -X POST http://ollama:11434/api/pull \
  -d '{"name":"nomic-embed-text"}'
```

(`nomic-embed-text` is 768-dim, ~270 MB, MIT-licensed.)

Confirm:

```bash
ddev exec --service web curl -s http://ollama:11434/api/tags
```

## Site setting

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'
  embedder:
    source: 'ollama'
    url: 'http://ollama:11434/api/embeddings'
    model: 'nomic-embed-text'
    documentTemplate: "{{ doc.title }}. {{ doc.description }}. {{ doc.bodytext }}"
    semanticRatio: 0.5
```

Don't forget the `vectorStore` experimental feature flip from
[03](03-hybrid-openai.md) — Meilisearch needs it regardless of which
embedder source you pick.

## Rebuild

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild
```

First run is slow — Ollama loads the model into memory on the first
embed request (~10s warmup). Subsequent reindexes / individual
DataHandler-triggered updates hit the in-memory model with low
latency.

## Hybrid query

Same as OpenAI: `?hybrid=1` on the FE plugin, or pass
`['hybrid' => true]` when calling `SearchService::search()` from PHP.

## Trade-off summary

| | OpenAI | Ollama |
|---|---|---|
| Setup effort | API key + billing | One DDEV service + `ollama pull` |
| Indexing cost | ~$0.02 per 1M tokens | CPU / GPU time |
| Query cost | ~$0.0000002 per query | CPU / GPU time |
| Data leaves the host? | Yes | No |
| Embedding quality (Eng) | Higher | Good (BGE / Nomic match small OpenAI models) |
| Cold start | None | ~10 s first call after model unload |

For dev / staging: Ollama. For high-traffic production with no data
residency concerns: OpenAI is hard to beat on convenience.

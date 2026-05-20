# 03 — Hybrid search with OpenAI embeddings

Add semantic understanding to the keyword index. Visitors who search
for `"how do I reset my password"` get pages titled `"account recovery"`
as hits — pure keyword wouldn't match.

## Prereq

- OpenAI API key with billing enabled
- Meilisearch 1.10+ with the **vectorStore** experimental feature on:

  ```bash
  ddev exec curl -s -X PATCH \
    -H 'Authorization: Bearer dev_master_key' \
    -H 'Content-Type: application/json' \
    -d '{"vectorStore":true}' \
    http://meilisearch:7700/experimental-features
  ```

  (One-time, server-wide. Persists across container restarts as long as
  the data volume survives.)

## Site setting

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'
  embedder:
    source: 'openAi'
    model: 'text-embedding-3-small'   # 1536 dim, ~$0.02 / 1M tokens
    apiKey: '%env(OPENAI_API_KEY)%'
    semanticRatio: 0.5                # default mix; 1.0 = pure semantic
    documentTemplate: "{{ doc.title }}. {{ doc.description }}. {{ doc.bodytext }}"
```

`OPENAI_API_KEY` comes from a `.env` file at the project root. TYPO3
v14's typed-settings resolver expands `%env(...)%` placeholders at
read time.

## Rebuild so docs get vectorized

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:reindex main --rebuild
```

The IndexerService:

1. Drops the index
2. Creates it
3. PATCHes `/indexes/site1_search/settings/embedders` with the
   configuration above (and waits synchronously on the settings task)
4. Sends every document — Meilisearch auto-calls OpenAI for each one
   and stores the resulting vector alongside the searchable fields

`scheduler:run` later picks up any changed records via the DataHandler
hook and re-vectorizes only those.

## Frontend usage

Append `&tx_wsmeilisearch_search[hybrid]=1` to the results URL, or
make the form expose a "smart search" toggle that POSTs `hybrid=1`.
The controller reads it, and the SearchService routes through the
Meilisearch SDK's `hybrid` parameter when both an embedder is
configured **and** the toggle is on.

## Cost sanity check

Indexing cost = (avg doc size in tokens) × (corpus size) × (price per token).
For 10k docs × 500 tokens avg × $0.00000002 = **$0.10** one-time
(plus the same for each `--rebuild`). Query cost = (query tokens) ×
(searches per day). A 10-token query at $0.02 / 1M = $0.0000002 per
query — essentially free.

## When OpenAI is the wrong choice

If you can't ship traffic to a US-based third party (EU sites with
strict data residency), or you want zero per-query cost, swap to
self-hosted Ollama — see [04](04-hybrid-ollama.md).

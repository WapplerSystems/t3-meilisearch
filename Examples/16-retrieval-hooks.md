# 16 — Reranking, hidden types and retrieval hooks

## Rerank the context (`meilisearch.rag.rerank.*`)

```yaml
meilisearch:
  rag:
    rerank:
      enabled: true
      candidates: 20          # documents the reranker sees (max 40)
      model: ''               # empty = meilisearch.rag.model
```

Retrieval fetches `candidates` documents, one short LLM call (temperature 0)
orders them by how directly they answer the question, then the context is
cut to `maxContextHits`. Useful when many documents of one topic compete —
course lessons next to the documentation. Any failure keeps the retrieval
order. Costs one extra LLM call per question; measure before enabling
(`ws_meilisearch:retrieval-check`).

## Keep a type out of the site search (`meilisearch.search.excludedTypes`)

```yaml
meilisearch.search.excludedTypes:
  - elearning
```

The visitor-facing search, its facets and recovery leave these types out,
like `knowledge_resource`; the chat still retrieves them.

## Hooks

- `BeforeRagQueryEvent` now carries the `site` (read site settings in a
  listener without a request).
- `RagClarificationEvent` — set `$allowed = false` to answer straight away
  instead of asking back (e.g. the product is known from earlier turns, or
  the topic is the same in every product). Skips the classification call.
- `RagCitationLabelsEvent::setLabel($id, $label, $qualifier, $note)` — the
  new `$note` is printed once below the source list however many cited
  documents carry it ("In newer releases the interface may differ."). The
  model cannot drop or reword it.

# Examples

End-to-end snippets that demonstrate what `ws_meilisearch` can do, from
the bare minimum keyword-search install through hybrid search to a
multi-turn RAG chatbot with cited sources. Every snippet is meant to be
read top-to-bottom and then either pasted into a site's
`settings.yaml` or adapted into a controller/listener.

## Layout

| File | What it shows |
|---|---|
| [`01-minimal-keyword-search.md`](01-minimal-keyword-search.md) | The smallest working `settings.yaml`. Pages + news indexed, FE plugin returns typo-tolerant results. |
| [`02-fal-with-tika.md`](02-fal-with-tika.md) | Add the Apache Tika service and start indexing PDFs / Office docs. Per-site file dedup is opt-in. |
| [`03-hybrid-openai.md`](03-hybrid-openai.md) | OpenAI embeddings configured per site, hybrid (semantic + keyword) search via `?hybrid=1`. |
| [`04-hybrid-ollama.md`](04-hybrid-ollama.md) | Same thing, self-hosted with Ollama — no API key, no per-query cost. |
| [`05-rag-anthropic.md`](05-rag-anthropic.md) | RAG chat backed by Claude. Single-turn, with cited sources. |
| [`06-rag-conversation.md`](06-rag-conversation.md) | Multi-turn conversation: follow-up questions inherit prior context. Session-backed. |
| [`07-event-listener-prompt-cache.md`](07-event-listener-prompt-cache.md) | Listener that short-circuits identical questions with a cached answer. |
| [`08-event-listener-query-rewriter.md`](08-event-listener-query-rewriter.md) | Listener that strips question words from verbose user queries before retrieval. |
| [`09-custom-schema-provider.md`](09-custom-schema-provider.md) | Index a third-party extension's records (e.g. `tx_products_product`) into the same unified index. |
| [`10-programmatic-api.md`](10-programmatic-api.md) | Call `SearchService` / `RagService` directly from your own controller or scheduler task. |
| [`11-rag-streaming.md`](11-rag-streaming.md) | Server-Sent Events: tokens render as the LLM generates them. Includes a drop-in JS client. |

Every example is self-contained; pick one and ignore the rest if you
already have the rest of the stack wired.

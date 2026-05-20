# 05 — RAG with Anthropic Claude

Single-turn "ask the site" chat. Visitor types a question, the RAG
plugin retrieves the top hits, asks Claude to summarize them with
inline `[id=…]` citations, and renders the answer + a sources list.

## Stack

- Search + hybrid stack from [03](03-hybrid-openai.md) or
  [04](04-hybrid-ollama.md). Hybrid is recommended for RAG because
  verbose user questions ("How do I X?") often miss keyword
  retrieval.
- Anthropic API key

## Site setting

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'

  # Hybrid recommended for RAG retrieval
  embedder:
    source: 'ollama'
    url: 'http://ollama:11434/api/embeddings'
    model: 'nomic-embed-text'

  rag:
    provider: 'anthropic'
    model: 'claude-haiku-4-5'
    apiKey: '%env(ANTHROPIC_API_KEY)%'
    maxContextHits: 5         # how many search hits to feed the LLM
    maxContextChars: 1500     # per-hit body truncation
    temperature: 0.2          # low → grounded; raise for more freedom
    useHybrid: true           # retrieve with semantic + keyword mix
    systemPrompt: |
      You are the helpful site assistant for example.com.
      Answer strictly from the provided context excerpts.
      If the answer isn't in the context, say so plainly.
      Cite each claim with [id=...] markers matching the IDs in the context.
      Respond in {{language}}.
```

## Plugin

Drop the **WsMeilisearch / RAG** content element on a page. It exposes
`form` (initial) and `ask` (?q=...) actions. The default action URL
shape:

```
/ask?tx_wsmeilisearch_rag[action]=ask
     &tx_wsmeilisearch_rag[controller]=Rag
     &tx_wsmeilisearch_rag[q]=How+do+I+reset+my+password
```

## CLI for tuning

```bash
ddev exec vendor/bin/typo3 ws_meilisearch:ask "How do I reset my password?" main
```

Output:

```
Site: main
Q: How do I reset my password?

Answer:
You can reset your password from the account page [id=pages-42]. ...

Sources fed into context (cited 2 of 5):
  ✓ [pages-42] Account & login
    [news-19] New feature: passwordless login
  ✓ [pages-58] Security FAQ
    [file-7] User manual.pdf
    [pages-12] Contact
```

Cited markers (✓) come from the regex pass on the LLM's response that
matches `[id=...]` against the actually-retrieved hit IDs — citations
the model hallucinates (i.e. IDs not in the context) get dropped.

## Tuning the system prompt

Common revisions:

- Add formatting instructions ("Answer in 2-3 sentences. Use bullets
  for steps.")
- Restrict response language ("Reply in English even if the question
  is in German.")
- Prepend a disclaimer ("Always end with 'This is not legal advice'
  for legal pages.")

Edit the `systemPrompt` setting; the next ask uses the new prompt.
No restart, no reindex.

## When Anthropic is the right call

When you want grounded, terse, well-cited answers and your traffic
volume justifies the per-token cost. `claude-haiku-4-5` is the cost-
sweet-spot model — quality close to bigger models, single-digit-ms
TTFT, ~$0.0003 per typical RAG question.

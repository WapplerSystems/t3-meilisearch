# 06 — Multi-turn RAG conversation

Builds on [05](05-rag-anthropic.md). Visitors can ask follow-up
questions without restating context — "Tell me more" works because
the controller remembers the prior turns and feeds them back to the
LLM.

## Site setting

```yaml
meilisearch:
  url: 'http://meilisearch:7700'
  apiKey: 'dev_master_key'
  indexPrefix: 'site1_'

  rag:
    provider: 'anthropic'
    model: 'claude-haiku-4-5'
    apiKey: '%env(ANTHROPIC_API_KEY)%'
    temperature: 0.2
    conversation:
      enabled: true              # opt-in — default is stateless
      maxTurns: 3                # cap; older pairs drop off the front
      sessionKey: 'ws_meilisearch_rag_conversation'
```

## Flow

```
Visitor          RagController            ConversationStore     RagService          LLM
   │                  │                          │                  │                 │
   │  GET /ask?q=Q1   │                          │                  │                 │
   ├─────────────────→│                          │                  │                 │
   │                  │ load(session, key)       │                  │                 │
   │                  ├─────────────────────────→│                  │                 │
   │                  │   ← empty Conversation   │                  │                 │
   │                  │ ask(site, Q1, {conv})    │                  │                 │
   │                  ├─────────────────────────────────────────────→│                 │
   │                  │                          │                  │  retrieve(Q1)   │
   │                  │                          │                  │  build prompt   │
   │                  │                          │                  │  + 0 prior turns│
   │                  │                          │                  ├────────────────→│
   │                  │                          │                  │   ← answer A1   │
   │                  │   ← RagAnswer(A1)        │                  │                 │
   │                  │ save({Q1, A1})           │                  │                 │
   │                  ├─────────────────────────→│                  │                 │
   │  ← page with A1  │                          │                  │                 │
   │                  │                          │                  │                 │
   │  GET /ask?q=Q2   │                          │                  │                 │
   ├─────────────────→│                          │                  │                 │
   │                  │ load → Conversation[Q1,A1]                  │                 │
   │                  │ ask(site, Q2, {conv})    │                  │                 │
   │                  │                          │                  │  retrieve(Q2)   │
   │                  │                          │                  │  build prompt:  │
   │                  │                          │                  │  [system,       │
   │                  │                          │                  │   user Q1,      │
   │                  │                          │                  │   assistant A1, │
   │                  │                          │                  │   user Q2+ctx]  │
   │                  │                          │                  ├────────────────→│
   │                  │                          │                  │   ← answer A2   │
   │                  │   ← RagAnswer(A2)        │                  │                 │
   │                  │ save({Q1,A1},{Q2,A2})    │                  │                 │
   │  ← page with A1+A2                          │                  │                 │
```

Key points:

- **Retrieval re-runs each turn.** Q2's search context is computed
  from Q2 itself, not from Q1. Follow-ups stay grounded even if the
  visitor pivots topic.
- **Bounded history.** `maxTurns: 3` means after the 4th question the
  oldest (Q, A) drops out — prompt size stays predictable.
- **Citations are per-turn.** Stored `citedIds` belong to the answer
  that generated them. The template can render "this answer cited X"
  for each turn independently.

## Reset

Add a "New conversation" link in your template that hits the new
`reset` action:

```html
<f:link.action action="reset">New conversation</f:link.action>
```

`resetAction` clears the stored conversation and redirects to
`form` so the next question starts fresh.

## Template integration

The controller exposes:

| Variable | Type | Notes |
|---|---|---|
| `question` | `string` | The new question (or empty). |
| `answer` | `RagAnswer` | The just-produced answer (only set after `ask`). |
| `conversation` | `list<Turn>` | All turns up to and including the just-produced one. |
| `conversationEnabled` | `bool` | True iff `conversation.enabled=true` in settings. |

Minimal chat-style Fluid template:

```html
<f:if condition="{conversationEnabled} && {conversation}">
    <ol class="chat-thread">
        <f:for each="{conversation}" as="turn">
            <li class="turn">
                <div class="bubble bubble--user">{turn.question}</div>
                <div class="bubble bubble--assistant">{turn.answer -> f:format.nl2br()}</div>
                <f:if condition="{turn.citedIds}">
                    <small>Cited:
                        <f:for each="{turn.citedIds}" as="id" iteration="i">
                            <code>{id}</code>{f:if(condition: '!{i.isLast}', then: ',')}
                        </f:for>
                    </small>
                </f:if>
            </li>
        </f:for>
    </ol>
</f:if>

<f:form action="ask" method="get" noCacheHash="true">
    <input type="text" name="tx_wsmeilisearch_rag[q]" placeholder="Ask…" />
    <button>Send</button>
</f:form>

<f:if condition="{conversationEnabled} && {conversation}">
    <f:link.action action="reset" class="btn btn-link">Start over</f:link.action>
</f:if>
```

## Privacy considerations

- State lives in the **anonymous TYPO3 frontend session**. The
  session cookie is set lazily by TYPO3 the first time we call
  `setAndSaveSessionData()` — so visitors who never ask anything
  never get a session cookie from this plugin.
- Stored data is the literal Q + A text. If your LLM might reveal
  personal data and you log/back up sessions, treat them accordingly.
- The `reset` action clears server-side state; the visitor's browser
  cookie stays, but the next read returns an empty Conversation.
- For zero-state setups, just leave `conversation.enabled: false`.

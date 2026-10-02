# 15 — MCP endpoint for AI clients

`<site base>/_ws_meilisearch/mcp` speaks the Model Context Protocol
(Streamable HTTP, stateless JSON responses). An AI client — Claude Code,
Claude Desktop, the Claude API MCP connector — can then ask the site
assistant, search the index, read the chat protocol and maintain the
hand-written knowledge (see 14).

## Enable and issue a token

```yaml
meilisearch:
  mcp:
    enabled: true
```

Backend → Meilisearch → **MCP-Zugänge**: title, scopes, sites (none = all),
the backend user writes run as, optional expiry. The token (`wsmcp_…`) is
shown once, together with the ready-made command:

```bash
claude mcp add --transport http main https://www.example.org/_ws_meilisearch/mcp \
  --header "Authorization: Bearer wsmcp_…"
```

Only the SHA-256 of a token is stored. Tokens can be locked, unlocked and
revoked; a revoked token cannot come back.

## Tools and scopes

| Tool | Scope | |
|---|---|---|
| `ask_assistant` | `ask` | question → answer + sources, `conversationId` for follow-ups |
| `search_index` | `search` | search without an answer, hits with URL and excerpt |
| `protocol_list`, `protocol_get` | `protocol:read` | conversations, filter for problems / escalations |
| `knowledge_list`, `knowledge_get` | `knowledge:read` | hand-written knowledge |
| `knowledge_create`, `knowledge_update`, `knowledge_delete` | `knowledge:write` | via DataHandler, indexed on save |

A client only sees the tools its scopes allow. Follow-ups are rebuilt from
the chat protocol, so they need `meilisearch.rag.protocol.enabled`. Turns
asked through MCP are stored with `channel = mcp` and marked in the protocol
view, so the site owner's own tests are not mistaken for visitor questions.

## Security model

* Token → scopes and sites. A token for site A gets 401 on site B.
* Writes run as the token's backend user, set up like a CLI user: its
  `tables_modify`, page permissions and web mounts apply, and the record
  history names it. Use a dedicated non-admin user with rights on
  `tx_wsmeilisearch_knowledge_entry` and the site root page only. A token
  with `knowledge:write` cannot be created without one.
* Search and ask run as an anonymous visitor: access-restricted documents
  never reach the client.
* Requests with a foreign `Origin` header are refused (DNS rebinding);
  command-line and API clients send none.
* No rate limiting in the extension: every `ask_assistant` call costs an LLM
  call. Limit at the web server if a token is handed to a wider group.

## Own tools

Implement `WapplerSystems\Meilisearch\Mcp\McpToolInterface` in any
extension; autoconfiguration tags it `ws_meilisearch.mcp_tool` and it
appears in `tools/list` for clients with its scope. Throw `McpToolException`
for errors the model should read.

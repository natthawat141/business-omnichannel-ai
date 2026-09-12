# Management Agent: setup

For the human-readable guide with navigation and copy buttons, open [Aion3 Docs](/docs/agent-setup).

Use this guide to install local document tools and optional human-reviewed proposal tools. Website paths below resolve against the HTTPS origin serving this document. The admin page `/admin/ai-setup` provides copyable commands with the correct origin already filled in.

## 1. Prepare the client machine

This is the Advanced / local setup guide. Requires Node.js 22 or newer, npm, Bash, and the chosen AI client installed on the same machine. No Docker, VM or database is needed on the client. For browser OAuth instead, read [/docs/remote-mcp.md](/docs/remote-mcp.md) and check whether Remote MCP is enabled on the setup page. Client/account support must be verified; do not fabricate connectivity.

Check `node --version` and `npm --version`. Inspect any existing document-intake configuration before changing it. Do not overwrite an existing installation or another MCP server. If the agent has a different sandbox from the user's AI client, explain where the commands must actually run.

The downloadable npm package is `/downloads/document-intake-agent-0.2.0.tgz`. It contains the CLI and local stdio MCP implementation, not customer documents or credentials. npm installs its runtime dependencies; network access to the npm registry is required. There is no globally published npm package to guess or search for.

In a new working directory, replace `https://management.example.com` with this guide's verified origin:

```bash
npm install --prefix ./management-agent https://management.example.com/downloads/document-intake-agent-0.2.0.tgz
cd ./management-agent/node_modules/@local/document-intake-agent
```

An agent can prepare this installation before a key exists. Do not claim the connection is verified at this stage.

## 2. The operator supplies a key

Create a temporary key at `/admin/ai-setup` when ready. Default expiry is one hour; 15 minutes and four hours are also available. Choose **Document metadata** for `documents:read` only, or explicitly choose **Proposal for admin review** and select Catalog, FAQ, and/or Knowledge. A proposal key has `agent:read`, `changes:write`, and only its chosen entity abilities. It can never approve, apply, publish, run SQL, or download a PDF.

The operator, not the agent, runs this block in a private interactive Bash terminal. Replace only the example origin. The key is entered at the hidden prompt and is not included in the command, chat or shell history:

```bash
export MANAGEMENT_API_BASE_URL='https://management.example.com'
read -r -s -p 'Key: ' MANAGEMENT_API_TOKEN
export MANAGEMENT_API_TOKEN
echo
```

Keep using this terminal for the next step. Agents must not print the environment or inspect the secret. Do not enter a real key into an agent's captured terminal input or chat. If a key was exposed, revoke it and replace it. Closing the terminal ends that shell's environment; already-running child processes may retain it until they exit. Server expiry/revocation still applies.

## 3. Register and verify

Run from the installed package directory in the same terminal after entering the key. If document-intake already exists in the client configuration, inspect its non-secret command/path and ask before replacing it. Do not delete unrelated MCP settings.

### Codex CLI

```bash
codex mcp add document-intake -- node "$PWD/bin/document-intake-mcp.js"
codex -c 'mcp_servers.document-intake.env_vars=["MANAGEMENT_API_BASE_URL","MANAGEMENT_API_TOKEN"]'
```

This launch explicitly forwards the two environment variables to the stdio process. An already-open app or a separate terminal does not automatically inherit them.

### Claude Code

```bash
claude mcp add --transport stdio document-intake -- node "$PWD/bin/document-intake-mcp.js"
claude
```

### Antigravity local MCP

Use MCP Servers → Manage MCP Servers → View raw config in Antigravity IDE. Menu names vary by version, so prefer opening the active configuration from the application. The current global path documented by Google is `~/.gemini/config/mcp_config.json`; use a private global config outside the repository, not a workspace file containing a real key.

The [HTML guide](/docs/agent-setup#antigravity) and admin setup provide a copyable JSON template under `mcpServers.document-intake` with command, args and env. Replace both executable and script placeholders with actual absolute paths. The operator replaces `PASTE_KEY_LOCALLY` in `MANAGEMENT_API_TOKEN` privately, saves, restricts file permissions and refreshes MCP Servers. Do not give the populated config to an agent or commit it. Preserve unrelated servers. When the token expires, replace it privately and refresh. This uses local stdio, not a remote endpoint.

Source: [Google Antigravity MCP](https://antigravity.google/docs/mcp). Configuration was checked against documentation; an actual authenticated Antigravity session has not been verified.

### Terminal / CLI command

```bash
node ./bin/document-intake.js list --limit 10
```

For MCP clients ask: `Use document_list with limit 10 and page 1. Show the document names and processing statuses.` A successful empty response means connected but no matching documents. Setup is not complete until the real client receives a successful response.

## Propose data for administrator review

The proposal key supports structured create, update, archive, and restore requests only through an immutable change set. It is not direct database CRUD.

1. Read `agent_schema` first.
2. Use `agent_records_search` and `agent_record_get` only for bounded, allowed record reads. For an update/archive/restore, copy the current `lock_version` as `expected_version`.
3. Run `agent_changes_preview`. It must succeed before submission and never changes business data.
4. If the operator asks to proceed, call `agent_changes_submit` with a new idempotency key. This creates a `proposed` change set only.
5. A signed-in administrator reviews sources and diff at `/admin/agent-changes`, approves, then separately applies it. Creates remain unpublished/inactive drafts.

Use [Management Proposals SKILL.md](/skills/management-proposals/SKILL.md) for the exact PDF/brochure workflow. It requires explicit facts, preserves unknown values, and forbids inferring live offers, price, availability, promotion, or policy from incomplete documents. A verified `document_id` must belong to the same administrator who issued the temporary proposal key.

## Troubleshooting and scope

- 401: key missing, expired, invalid or revoked. Create a replacement, repeat the hidden prompt and relaunch the client from that terminal.
- 403: wrong scope; ask the administrator to verify `documents:read` or the exact proposal entity ability, not to grant all permissions.
- 429: wait according to the retry delay; do not loop.
- Missing tools: check package path, client registration and client restart. Configuration alone does not prove connectivity.
- Network/proxy timeout: report the network failure separately from token validity. Do not disable TLS verification.

Document tools expose metadata only: `document_list` and `document_get`. They cannot read PDF contents, upload, or perform OCR. Proposal tools can submit a reviewable draft only; they cannot approve, apply, publish, or query a database. See [document skill](/skills/document-intake/SKILL.md) and [proposal skill](/skills/management-proposals/SKILL.md) for schemas and limits.

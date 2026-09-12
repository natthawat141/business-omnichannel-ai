# Management Agent Tooling (CLI & MCP)

A lightweight, standalone TypeScript package providing scoped document-metadata reads and human-reviewed business-data proposals through the Management HTTP API.

Supported integration surfaces:
- **CLI (`document-intake`)**: List and inspect document metadata; read proposal schema; preview and submit an immutable proposal.
- **MCP Server (`document-intake-mcp`)**: Stdio-based Model Context Protocol server for compatible local agents (Codex, Claude Code, Antigravity, Hermes, OpenClaw).

---

## Strict Security and Boundary Rules

- **Separate least-privilege scopes**: A `documents:read` key exposes only `document_list`/`document_get`. An explicit proposal key can read only selected `catalog`, `faq`, and/or `knowledge` records and submit a change set. The key has no tool to approve, apply, publish, delete, upload, OCR, or change schema.
- **No Direct DB Access**: All operations query authenticated Management HTTP endpoints (`GET /api/v1/documents`, `GET /api/v1/documents/{document}`). Direct database/SQL access is strictly prohibited.
- **No Public URLs / File Downloads**: Underlying PDF files in private storage are never exposed, downloadable, or linked.
- **Data Redaction**: Internal storage paths, disks, SHA-256 hashes, raw metadata blobs, and user identifiers are strictly redacted.
- **Token Security**: Tokens are read solely from environment variables (`MANAGEMENT_API_TOKEN`). Tokens and Authorization headers are never printed, logged, or emitted in error messages.
- **Transport Safety**: HTTPS is required for all remote connections. Plain HTTP is permitted only for explicit local development (`localhost`, `127.0.0.1`, `[::1]`).
- **Stdio Transport Only**: The MCP server operates strictly over `stdio`. It does not listen on any network port or start a background daemon.

---

## Token Issuance (Least Privilege)

The Document Intake API requires a token with the specific ability `documents:read`. A generic `read` or analytics token will be rejected with HTTP 403 Forbidden. Proposal access is issued only by the Management **Agent setup** page as `agent:read`, `changes:write`, and `agent:<entity>` abilities. Do not hand-assemble broad abilities or use `*`.

To issue a least-privilege token from `apps/management`, run:

```bash
php artisan api-token:issue "Document Intake Agent" --ability=documents:read
```

> **Note**: The command displays the plaintext token **once**. Store the token securely in your local secret manager or environment configuration. Never commit tokens to source control.

---

## Configuration

Set the required environment variables:

| Variable | Description | Example |
|---|---|---|
| `MANAGEMENT_API_BASE_URL` | Base URL of the Management service (HTTPS required unless localhost) | `http://localhost:8000` or `https://management.example.com` |
| `MANAGEMENT_API_TOKEN` | Bearer token with `documents:read` ability | `<YOUR_MANAGEMENT_API_TOKEN>` |

---

## Installation, Build, and Tests

From this directory (`tools/document-intake-agent`):

### Typecheck
```bash
npm run typecheck
```

### Build
```bash
npm run build
```

### Test
Run unit tests with local mocks (no external services or network calls required):
```bash
npm test
```

### Lint
```bash
npm run lint
```

---

## CLI Usage (`document-intake`)

### List Documents
List intake documents with optional status filtering, page number (1–1000), and bounded limits (1–50, default 15):

```bash
# List recent documents
node ./bin/document-intake.js list

# Filter by status with custom limit and page
node ./bin/document-intake.js list --status ready --limit 10 --page 1
```

Allowed `--status` values:
- `uploaded`
- `extracting`
- `ready`
- `ocr_required`
- `failed`

*(Note: `cancelled` documents are excluded from the external agent interface and will return a validation error if requested).*

### Inspect a Document
Retrieve allowlisted metadata for a specific document by its integer ID:

```bash
node ./bin/document-intake.js get 42
```

Outputs safe, sanitized JSON:
```json
{
  "meta": {
    "version": "1.0"
  },
  "data": {
    "id": 42,
    "source_type": "upload",
    "original_filename": "property_summary.pdf",
    "mime_type": "application/pdf",
    "file_size": 204800,
    "status": "ready",
    "page_count": 3,
    "failure_category": null,
    "created_at": "2026-09-04T12:00:00+07:00",
    "updated_at": "2026-09-04T12:05:00Z"
  }
}
```

---

## Proposal workflow (v0.2.0)

With an explicitly issued proposal key, this package exposes bounded tools/commands for:

1. `agent_schema` / `schema` — discover allowed entities and limits.
2. `agent_records_search`, `agent_record_get` / `records`, `record` — bounded record reads and lock version discovery.
3. `agent_changes_preview` / `changes-preview proposal.json` — validate without changing business data.
4. `agent_changes_submit` / `changes-submit proposal.json --idempotency-key KEY` — store an immutable review request only.
5. `agent_changes_get` / `changes-get UUID` — read the status of the same key's proposal.

The server never offers approve/apply/publish/SQL tools. Administrators review and separately apply a proposal in `/admin/agent-changes`. New records are draft-only. Read [the public proposal skill](../../apps/management/public/skills/management-proposals/SKILL.md) for PDF evidence, unknown values, hierarchy, and retry rules.

## MCP Server (`document-intake-mcp`)

The MCP server connects over standard input/output (`stdio`) and provides document tools plus six proposal-only tools when the key permits them:
1. `document_list`: Accepts optional `status` (enum), `limit` (integer 1–50), and `page` (integer 1–1000).
2. `document_get`: Accepts a required positive integer `id`.
3. `agent_schema`, `agent_records_search`, `agent_record_get`, `agent_changes_preview`, `agent_changes_submit`, `agent_changes_get`.

### Running Locally
```bash
node ./bin/document-intake-mcp.js
```

### MCP Client Configuration Templates

Depending on the host client's capabilities, configure the stdio server as shown below:

#### Claude Desktop Configuration (`claude_desktop_config.json`)
```json
{
  "mcpServers": {
    "document-intake": {
      "command": "node",
      "args": [
        "/ABSOLUTE/PATH/TO/line-bot-chatwoot/tools/document-intake-agent/bin/document-intake-mcp.js"
      ],
      "env": {
        "MANAGEMENT_API_BASE_URL": "http://localhost:8000",
        "MANAGEMENT_API_TOKEN": "YOUR_API_TOKEN_HERE"
      }
    }
  }
}
```

#### Claude Code (`claude.json` / MCP config)
```json
{
  "mcpServers": {
    "document-intake": {
      "command": "node",
      "args": [
        "/ABSOLUTE/PATH/TO/line-bot-chatwoot/tools/document-intake-agent/bin/document-intake-mcp.js"
      ],
      "env": {
        "MANAGEMENT_API_BASE_URL": "http://localhost:8000",
        "MANAGEMENT_API_TOKEN": "YOUR_API_TOKEN_HERE"
      }
    }
  }
}
```

#### Hermes / OpenClaw / Custom Agent Configuration Template
```json
{
  "tools": {
    "document-intake": {
      "transport": "stdio",
      "command": "node",
      "args": [
        "/ABSOLUTE/PATH/TO/line-bot-chatwoot/tools/document-intake-agent/bin/document-intake-mcp.js"
      ],
      "env": {
        "MANAGEMENT_API_BASE_URL": "http://localhost:8000",
        "MANAGEMENT_API_TOKEN": "YOUR_API_TOKEN_HERE"
      }
    }
  }
}
```

---

## Client Support Limits & Requirements

- **Stdio Transport Dependency**: This server requires an MCP host that natively launches local sub-processes over standard input/output (`stdio`).
- **ChatGPT / Codex Support**: Standard hosted ChatGPT and Codex web interfaces do **not** natively spawn or communicate with local `stdio` sub-processes on a user's machine without a separate local bridge or specialized client. Do not assume automatic stdio MCP support in pure cloud-hosted agent interfaces.
- **Client Configuration Prerequisite**: Every client must supply valid `MANAGEMENT_API_BASE_URL` and `MANAGEMENT_API_TOKEN` environment variables; the server will fail closed and exit immediately if these variables are absent or invalid.

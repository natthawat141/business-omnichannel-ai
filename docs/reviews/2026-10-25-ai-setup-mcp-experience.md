# AI Setup and Remote MCP experience

## Result

- Browser and local MCP/CLI setup now appear on one `/admin/ai-setup` page. The legacy
  `?advanced=1` URL renders the same page.
- The page has a safe whole-guide copy action, shared task/entity selection, visible ordered
  local setup instructions, explicit key issuance, truthful connection status and recovery
  steps. The export is composed from safe guide data; it does not read DOM fields or include
  the one-time key.
- Remote MCP responses now expose titles, output schemas, structured content matching the
  JSON text response, bounded record pagination and recoverable errors. Existing tool names,
  scopes and human-only apply/publish boundary remain in place.
- The compatibility Agent Setup page re-exports the unified page. The local downloadable MCP
  package was not rebuilt or replaced.

## Design and implementation notes

The setup page keeps the existing Management layout, Thai typography, controls and light/dark
tokens. A single readable content column replaces the old compressed advanced workflow. This
applies the repository design-system guidance and gives the steps a linear order: prepare the
client, install, issue/register a key, then verify. On narrow screens the workflow stacks and
code/prompt regions scroll without forcing page-wide horizontal overflow.

MCP improvements follow the linked [Anthropic MCP Builder guidance](https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md)
and [MCP tool result specification](https://modelcontextprotocol.io/specification/2025-06-18/server/tools):
clear titles and descriptions, structured results for capable clients, bounded paging, actionable
errors, and independent workflow evaluation. This does not establish compatibility with every
third-party MCP client.

## Verification

- PHP: Full Management suite passed (244 tests, 1,382 assertions), including `AiSetupTest`,
  `RemoteMcpTest`, `AgentChangesetTest`, `DocumentApiTest` and legacy AI Setup contract checks.
- Frontend: `npm run typecheck`, `npm run lint`, and production build passed. Existing build emits
  the large JavaScript chunk warning; this change did not split unrelated bundles.
- Browser harness: 10/10 checks passed on built assets with synthetic props and keys. Covered
  combined sections, full-page copy, changing selection/export, key masking and exclusion,
  four local clients, denied clipboard fallback, revoke cancel/failure/retry, 390px layout,
  advanced alias/disabled transport, and dark mode/focus. The local HTTP server bound only to
  loopback. No real credentials or production requests were used.
- MCP workflow evaluation: 10 deterministic read-only protocol cases passed, covering metadata,
  version reads, pagination, exact empty results, permission recovery, batch limits, structured
  parity, missing-record recovery, preview without submission, and validation repair. This is a
  protocol-workflow test, not a language-model quality score.
- `git diff --check` passed.

## Release status

The change is implemented and locally verified. It has not been deployed. `AGENTS.md` requires
separate product-owner approval for production deployment. No live key was issued and no live
connection was revoked during this work.

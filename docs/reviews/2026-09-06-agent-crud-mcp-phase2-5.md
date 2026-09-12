# Review: human-reviewed agent proposals — Phases 1B–5

Date: 2026-09-06
Implementer/reviewer: Codex
Status: **ready for code review; not production-ready or deployed**

## Scope and decision boundary

Implements SPEC `FR-AGENT-001` through `FR-AGENT-008` in an isolated development
workspace. The PDF is interpreted by an external client agent; Management validates bounded
structured proposals and keeps the human review/apply gate. The customer-facing Chatwoot AI
remains read-only.

No production migration, production deploy, real key issuance, real customer PDF ingest,
Google Drive sync, OCR/LLM service, remote MCP/OAuth, SQL tool, direct database access, queue,
or local Docker was introduced.

## Delivered behavior

### Catalog lifecycle and compatibility

- Additive migrations add category schema versioning; `group → variant → offer`, `parent_id`,
  optimistic `lock_version`, and recoverable `archived_at`; public retrieval returns only live
  offers.
- Existing direct admin forms and 9/23/24-column imports remain supported. New forms submit a
  lock version; older form clients that omit it remain compatible using the freshly bound record
  version. This does not change customer read API contracts.
- A Business Profile singleton regression is fixed: an existing row is reused even when its ID is
  not `1`, preventing creation of a second profile after an auto-increment sequence advances.

### Proposal engine and audit trail

- `/api/v1/agent` has a bounded, entity-scoped schema/read/preview/submit/status contract.
  There is no generic table, SQL, file-path, apply, approve, or publish endpoint.
- Each change set is immutable and per-key idempotent. Submission only persists operations;
  preview and submit never modify business records.
- Apply runs in one transaction, revalidates target versions and category schema versions, and
  rolls back the entire batch on conflict. Key revocation suspends pending batches.
- `record_revisions` saves before/after snapshots and `record_sources` records private
  `DocumentSource` page/field evidence or explicitly unverified external labels. Cancelled
  documents are rejected as evidence. A private document may only be claimed by a proposal key
  issued to that document's uploading staff member; the check is repeated at apply time so a
  source-ownership race cannot create a verified audit record.
- Catalog creates become active-but-unpublished `availability=unknown` drafts. FAQ and Knowledge
  creates are inactive drafts. Neither an API key nor MCP/CLI can apply or publish.

### Human admin review and setup UX

- `/admin/agent-changes` shows the operation diff, source references, status and guarded
  Approve / Apply / Reject actions. Only an administrator session protected by normal web CSRF
  routes can apply.
- `/admin/ai-setup` now clearly selects either document metadata read access or opt-in proposal
  access for Catalog, FAQ and/or Knowledge. Arbitrary abilities posted by the browser are ignored.
  Plaintext keys remain one-time JSON responses, never Inertia props, prompt content, URLs, or
  browser storage.
- Public SaaS-style setup docs, `llms.txt`, and a credential-free
  `management-proposals` skill document the local stdio workflow and state that Work/Cowork
  remote connection is unavailable.
- The AI setup page now uses Base UI tabs and tooltip primitives with a reusable terminal card,
  per-client Agent/Manual setup, a credential-free Copy Page action, and a responsive
  documentation side rail. It keeps the existing light admin shell while making code examples
  look and behave like developer documentation. Copy Page deliberately excludes the one-time
  issued key.

### CLI and MCP v0.2.0

- Keeps `document_list` and `document_get` unchanged.
- Adds `agent_schema`, `agent_records_search`, `agent_record_get`,
  `agent_changes_preview`, `agent_changes_submit`, and `agent_changes_get`.
- Adds matching CLI commands: `schema`, `records`, `record`, `changes-preview`,
  `changes-submit`, and `changes-get`.
- Versioned credential-free archive:
  `apps/management/public/downloads/document-intake-agent-0.2.0.tgz`
  (SHA-256 `d768700d9367f81a13b9c3a92e43bb1f642eba41594c45b3e985935316e3df15`).

## Key files

- Migrations: `apps/management/database/migrations/2026_09_06_000002_*` through
  `2026_09_06_000004_*` (forward-only safeguards).
- Proposal service/API/admin: `apps/management/app/Services/Agent/AgentChangeService.php`,
  `app/Http/Controllers/Api/Agent/AgentChangeApiController.php`,
  `app/Http/Controllers/Admin/AgentChangeController.php`, and `resources/js/pages/AgentChanges/`.
- Token/setup/docs: `AiSetupController.php`, `AiSetup.tsx`, public docs/skills, and
  `apps/management/docs/AI_SETUP.md`.
- Local package: `tools/document-intake-agent/`.

## Verification evidence

Final verification and archive build ran on GCP project `aione-zone1`, isolated development VM
`dev-container-1`, zone `asia-southeast1-b`, in
`/home/macarthur/builds/agent-crud-20260906`. The PHP test container used a disposable bind mount
and synthetic SQLite database. No production volume, database, or production container was
mounted or restarted. The production VM `ai-bot-chatwoot-vm` was inspected read-only only.

| Check | Result |
|---|---|
| Laravel PHPUnit full suite | PASS — 182 tests, 747 assertions (PHP 8.4.25) |
| Cross-user private-document evidence regression | PASS — 1 test, 4 assertions; key cannot claim another staff member's document |
| Management frontend `npm run typecheck` | PASS |
| Management frontend `npm run lint` | PASS |
| Management frontend `npm run build` | PASS; bundle-size warning (567.09 kB JS) remains |
| AI setup UI refresh — targeted Laravel feature tests | PASS — 10 tests, 81 assertions (PHP 8.4.25) |
| AI setup UI refresh — `npm run typecheck`, `npm run lint`, `npm run build` | PASS — verified in the isolated GCP VM; build warning is now 673.33 kB JS after Base UI is added |
| MCP/CLI `npm run typecheck` | PASS |
| MCP/CLI `npm test` | PASS — 20 tests; MCP in-memory protocol and token-redaction coverage included |
| MCP/CLI `npm run build` and `npm pack` | PASS — 14-file archive manifest |
| Clean install of the packed archive | PASS; CLI smoke test passed and archive has no `.env`, `node_modules`, tests, or fixtures |
| `git diff --check` | PASS |

The first remote test attempt exposed missing writable Laravel cache directories and an intentionally
excluded Vite manifest in the disposable test copy. Those directories and the frontend build were
created only on the development VM before the final run. macOS AppleDouble sidecars in the copied
test tree were removed only from that temporary copy; repository files were not deleted.

## Reviewer checklist

1. Confirm the intentional authority boundary: agents submit; administrators approve and apply;
   publish remains separate.
2. Inspect the record/source/revision retention and whether a future production retention policy is
   required before pilot.
3. Check the direct-admin compatibility fallback for clients that do not yet submit `lock_version`.
4. Confirm whether this single-business deployment should allow FAQ/Knowledge proposal keys in the
   first pilot or begin Catalog-only.
5. Verify an actual local Codex, Claude Code, and Antigravity client before promising those user
   environments are connected. Package tests are not client-account verification.

## Required gates before any production action

- Explicit product-owner approval for the exact VM/release, production database backup and tested
  restore, additive migration, and artifact deployment.
- Isolated MySQL concurrency test plus backup/restore drill.
- Review of a synthetic end-to-end proposal, then separate approval before a real PDF or business
  record pilot.
- Per-client local install/connection matrix. Work/Cowork need a separate authenticated remote
  MCP/OAuth design and implementation; do not use the website URL as a connector.

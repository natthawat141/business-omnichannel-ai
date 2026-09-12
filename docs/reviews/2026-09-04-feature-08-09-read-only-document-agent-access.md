# Feature 08/09 Review Packet — Read-Only Document Intake Agent Access (CLI & Stdio MCP)

## Review Status

- **Status:** **TECHNICALLY_APPROVED_BY_CODEX**
- **Requirement IDs:** `FR-DOC-014a` (narrowed read-only slice of `FR-DOC-014`), `NFR-DOC-002`, `NFR-DOC-004`, `NFR-DOC-005`
- **Reviewer:** Codex
- **Date:** 2026-09-04

---

## Architectural Tension and Justification

### Contract Tension with SPEC.md
`SPEC.md` strictly establishes that:
1. Management/MySQL is the source of truth for business data;
2. AI and external agents must never directly access the MySQL database;
3. Autonomous catalog modification, publishing, and uncontrolled draft writes are prohibited in Version 1;
4. The system boundary mandates authenticated HTTP APIs with scoped authorization.

In the proposed intake workflow (`docs/proposals/PDF_DRIVE_DOCUMENT_INTAKE_SPEC_ADDENDUM.md`), Features 02–07 (digital text extraction, OCR, AI candidate generation, candidate review/drafting, and Google Drive import) remain **strictly unapproved**.

### Why Feature 08/09 Read-Only Precedes Extraction
The Product Owner explicitly authorized a minimal, read-only metadata slice of Features 08 and 09 prior to extraction implementation. This slice is strictly a **passive visibility adapter**, not a document-processing or authoring pipeline:
- It allows authorized external agent tools (Claude Code, Codex, Hermes, OpenClaw) and administrators to inspect intake metadata, status lifecycles, and failure categories via authenticated Management HTTP endpoints.
- It operates **read-only** (`GET /api/v1/documents`, `GET /api/v1/documents/{document}`).
- It completely lacks upload, OCR, extraction, candidate authoring, draft creation, catalog modification, publishing, or raw file download capabilities.
- It enforces the single-business boundary: zero direct database access, zero background workers, zero listening HTTP servers, and zero Docker dependencies.

---

## Scope Completed & Codex Feedback Addressed

### 1. Management Read-Only Document Intake API
- **Routes:**
  - `GET /api/v1/documents` (`api.v1.documents.index`)
  - `GET /api/v1/documents/{document}` (`api.v1.documents.show`)
- **Authentication & Scoping:**
  - Guarded by `api.token:documents:read` middleware.
  - Tokens with generic `read` ability fail with HTTP 403 Forbidden.
  - Unauthenticated requests fail with HTTP 401 Unauthorized.
- **Rate Limiting:**
  - Rate-limited with `throttle:60,1` (60 requests/minute).
- **Safe Allowlisted Metadata Response (`DocumentApiResource`):**
  - Exposed fields: `id`, `source_type`, `original_filename`, `mime_type`, `file_size`, `status`, `page_count`, `failure_category`, `created_at`, `updated_at`.
  - **Blocker 1 Resolution (Strict Failure Category Isolation):**
    - `failure_reason` is **strictly excluded** from API responses to prevent raw internal exception text leakage.
    - Server-side mapping evaluates `$this->failure_reason`: if null/empty, returns null. If it matches a known fixed failure enum (`invalid_pdf`, `empty_file`, `oversized`, `security_rejected`, `processing_error`), that category is returned. Any unknown, arbitrary, or raw error string is safely collapsed to `processing_error`.
  - **Strict Redaction:** `storage_path`, `storage_disk`, `file_hash` (SHA-256), `meta` (debug/JSON blobs), `user_id`, and `user` object are excluded.
  - Zero raw file download endpoints or public URLs.
- **Filtering, Pagination, and Cancelled Semantics:**
  - `status` query filter is allowlisted to `uploaded`, `extracting`, `ready`, `ocr_required`, `failed`.
  - `limit` query parameter is bounded to `1..50` (default 15).
  - **Blocker 2 Resolution (Page Bounds):** `page` is bounded to `1..1000` (default 1). Requests with `page > 1000` or `page < 1` return HTTP 422 validation errors.
  - Cancelled documents represent deleted private files; `GET /api/v1/documents` strictly excludes cancelled records, and `GET /api/v1/documents/{document}` returns HTTP 404 Not Found rather than exposing cancelled internal state.

### 2. Standalone Local Tool Package (`tools/document-intake-agent`)
- **Isolation:** Implemented under `tools/document-intake-agent` without overloading the Laravel application or frontend bundle.
- **Safe HTTP Client (`ManagementClient`):**
  - Configured solely by `MANAGEMENT_API_BASE_URL` and `MANAGEMENT_API_TOKEN`.
  - **Blocker 3 Resolution (Protocol Validation):** Only `http:` (explicit localhost: `localhost`, `127.0.0.1`, `[::1]`) or `https:` is permitted. Schemes such as `file:`, `ftp:`, `ws:`, etc., are rejected immediately even when pointing to localhost.
  - Bounded 5-second timeout via `AbortSignal.timeout(5000)`.
  - **Blocker 4 Resolution (Strict Shape Validation & No Coercion):** `sanitizeItem` validates every field shape (positive integer `id`, non-empty strings for `source_type`/`original_filename`/`mime_type`, non-negative integer `file_size`, allowlisted `status`, positive/null `page_count`, allowlisted/null `failure_category`). Rejects malformed records and fails closed rather than inventing/coercing fallback values.
  - **Blocker 6 Resolution (Global Token Redaction):** Network errors sanitize all occurrences of `this.token` using `replaceAll(this.token, '[REDACTED]')`.
- **CLI (`document-intake`):**
  - Commands: `document-intake list [--status STATUS] [--limit N] [--page N]` and `document-intake get <id>`.
  - Enforces `page` bounds `1..1000` and `limit` `1..50`. Emits sanitized JSON with `failure_category` only (no `failure_reason`).
- **MCP Stdio Server (`document-intake-mcp`):**
  - Operates strictly over standard I/O (`StdioServerTransport`). Does not listen on any network port.
  - Exposes only tools `document_list` and `document_get` with strict Zod parameter validation including `page` (max 1000).
  - **Blocker 5 Resolution (Real Protocol Testing):** MCP test suite uses SDK's `InMemoryTransport.createLinkedPair()` and MCP `Client` to verify that `listTools()` returns exactly `['document_get', 'document_list']` and executes actual handlers through the protocol.

---

## Explicit Exclusions

The following capabilities are strictly omitted and forbidden in this slice:
- File upload, replacement, or deletion via agent tools.
- PDF text extraction, OCR, or parser execution.
- LLM prompt orchestration, candidate extraction, or candidate review.
- Catalog draft creation, publishing, or modification of `packages` or `knowledge_entries`.
- Raw file download or exposure of private storage paths.
- Google Drive OAuth, picker, or folder synchronization.
- Direct database connection or SQL query generation.
- Background workers, queues, Redis, Docker, or container modifications.
- Listening HTTP MCP transport or public network services.

---

## Changed Files

| File | Change Type | Rationale |
|---|---|---|
| `apps/management/app/Http/Resources/Api/DocumentApiResource.php` | Added | Safe allowlisted metadata resource exposing `failure_category` only (mapping known enums, collapsing raw text to `processing_error`), excluding `failure_reason` and internal paths |
| `apps/management/app/Http/Controllers/Api/DocumentApiController.php` | Added | Controller implementing bounded `index` (`limit: 1..50`, `page: 1..1000`) and `show` actions with status allowlist and 404 semantics |
| `apps/management/routes/api.php` | Modified | Registered versioned endpoints guarded by `api.token:documents:read` and rate limiter |
| `apps/management/tests/Feature/DocumentApiTest.php` | Added | 12 feature tests covering auth, ability isolation, metadata redaction, failure category mapping & collapsing, filtering, page 1000 bounds, and 404 semantics |
| `apps/management/docs/DOCUMENT_INTAKE.md` | Modified | Documented Section 6 (External Agent Read-Only API, `failure_category` collapsing, page bounds 1..1000, and publish prohibition) |
| `docs/proposals/PDF_DRIVE_DOCUMENT_INTAKE_SPEC_ADDENDUM.md` | Modified | Added explicit Product Owner authorization note for read-only slice and defined `FR-DOC-014a` |
| `tools/document-intake-agent/package.json` | Added | Standalone package configuration with build, test, typecheck, and lint scripts |
| `tools/document-intake-agent/package-lock.json` | Added | Reproducible lockfile for local dependencies |
| `tools/document-intake-agent/tsconfig.json` | Added | TypeScript compiler configuration (ES2022, NodeNext) |
| `tools/document-intake-agent/src/types.ts` | Added | TypeScript interfaces and allowlist constants (`failure_category` only, `ALLOWED_FAILURE_CATEGORIES`) |
| `tools/document-intake-agent/src/client.ts` | Added | Safe HTTP client with protocol validation (rejects `file:`, `ftp:`), strict shape validation in `sanitizeItem`, page 1000 bound, and global token redaction |
| `tools/document-intake-agent/src/cli.ts` | Added | CLI runner implementing `list` and `get` with `page: 1..1000` validation |
| `tools/document-intake-agent/src/mcp.ts` | Added | MCP server over stdio exposing `document_list` and `document_get` with Zod validation |
| `tools/document-intake-agent/src/index.ts` | Added | Package entrypoint exporting client, CLI, MCP, and types |
| `tools/document-intake-agent/bin/document-intake.js` | Added | Executable binary wrapper for CLI |
| `tools/document-intake-agent/bin/document-intake-mcp.js` | Added | Executable binary wrapper for stdio MCP server |
| `tools/document-intake-agent/src/tests/client.test.ts` | Added | Unit tests for protocol rejection, page bounds, failure category isolation, strict record shape rejection, and global token redaction |
| `tools/document-intake-agent/src/tests/cli.test.ts` | Added | Unit tests for CLI argument parsing, option rejection, page 1000 bound, and execution |
| `tools/document-intake-agent/src/tests/mcp.test.ts` | Added | Protocol tests via `InMemoryTransport` verifying tool listing (exact 2 tools) and handler execution |
| `tools/document-intake-agent/README.md` | Added | Package documentation, setup instructions, CLI examples with `failure_category`, page 1000 bounds, MCP client templates, and boundaries |
| `docs/reviews/2026-09-04-feature-08-09-read-only-document-agent-access.md` | Added | This review packet |

---

## Verification Evidence

| Test Suite / Command | Location | Result | Notes / Details |
|---|---|---|---|
| `php artisan test tests/Feature/DocumentApiTest.php` | `apps/management` | **Passed** | 12 tests, 83 assertions in 468ms. Verifies 401 unauthenticated, 403 generic `read` token, 200 `documents:read` token, metadata allowlisting, private field redaction, failure category mapping (known enums) and collapsing (unknown/raw text collapsed to `processing_error`, `failure_reason` excluded), valid status filtering, invalid status & cancelled rejection (422), pagination bounds including `page > 1000` (422) and `page = 1000` (200), cancelled document exclusion from list, single document detail, 404 for cancelled document, and 404 for invalid/nonexistent ID. |
| `php artisan test tests/Feature/DocumentUploadTest.php` | `apps/management` | **Passed** | 14 tests, 91 assertions in 880ms. Confirms no regression on Feature 01 admin upload lifecycle. |
| `npm run typecheck` | `tools/document-intake-agent` | **Passed** | `tsc --noEmit` exited with code 0. |
| `npm run lint` | `tools/document-intake-agent` | **Passed** | `tsc --noEmit` exited with code 0. |
| `npm test` | `tools/document-intake-agent` | **Passed** | 19 tests passed in 265ms via Node native test runner (`node --test`). Verifies protocol rejection (`file:`, `ftp:`), `page` 1..1000 bounds, `failure_category` isolation, strict record shape validation rejecting malformed records, global token redaction, CLI parsing, and real MCP protocol tool listing/call execution via `InMemoryTransport`. |
| `git diff --check` | Repository root | **Passed** | Zero whitespace or formatting conflicts. |

---

## Security and Privacy Review

1. **Failure Category Isolation & Collapsing:**
   - `failure_reason` is strictly omitted from responses.
   - Any raw, unmapped, or detailed exception string is collapsed to the generic `processing_error` enum, preventing internal path, database, or stack trace leaks.
2. **Ability Isolation (`documents:read`):**
   - The API middleware `api.token:documents:read` rejects tokens holding only generic `read` or analytics abilities with HTTP 403 Forbidden.
3. **Metadata Redaction & Private Storage Protection:**
   - Server-side and client-side schemas omit storage paths (`storage_path`), disk identifiers (`storage_disk`), SHA-256 hashes (`file_hash`), raw metadata blobs (`meta`), and user owner details (`user_id`).
   - No file bytes or text are returned; no download endpoints exist.
4. **Cancelled Document 404 Semantics:**
   - Cancelled documents have their private files purged upon cancellation.
   - Querying a cancelled document returns HTTP 404 Not Found, preventing leakage of cancelled private lifecycle states.
5. **Global Credential Redaction:**
   - Token values are read strictly from runtime environment variables.
   - All occurrences of the token are sanitized using `replaceAll` with `[REDACTED]`.
6. **Protocol & Input Security:**
   - `ManagementClient` enforces HTTPS for remote hosts and permits HTTP only for explicit localhost development. Arbitrary schemes like `file:` or `ftp:` are strictly rejected.
   - Strictly positive integer validation prevents directory traversal patterns (`../`) or SQL-like injections in URL parameters.
7. **No Daemon / Stdio Isolation:**
   - The MCP server uses `StdioServerTransport` only and does not open any listening TCP socket or port on the host machine.

---

## Known Limitations

1. **Client Stdio Capability Prerequisite:**
   - The MCP server relies on standard I/O communication spawned by local host clients (e.g. Claude Desktop, Claude Code, Hermes). Cloud-hosted web interfaces without a local process bridge cannot connect directly to this local stdio tool.
2. **Read-Only Scope:**
   - External agents cannot initiate document processing, upload files, or trigger extraction in this slice.
3. **Storage Filesystem State:**
   - AppleDouble metadata files (`._*`) inherent to the external volume are preserved as user state and not deleted.
4. **Deployment Target:**
   - Per product-owner constraints, any future deployment/resource creation will target `GPC aione_zone1` only; no external environment resources were provisioned or deployed in this slice.

---

## Reviewer Checklist (TECHNICALLY_APPROVED_BY_CODEX)

- [x] `FR-DOC-014a` satisfied: external agents can list and inspect document metadata via authenticated HTTP API, CLI, and stdio MCP.
- [x] Only `failure_category` is exposed; `failure_reason` is strictly excluded; unknown failure text collapses to `processing_error`.
- [x] `page` is bounded to `1..1000` identically across Laravel validation, TS client, CLI, MCP Zod schema, docs, and tests.
- [x] `ManagementClient` permits only `https:` or `http:` (localhost only), strictly rejecting `file:`, `ftp:`, etc.
- [x] `sanitizeItem` strictly validates required field shapes and fails closed on malformed records.
- [x] MCP tests prove tool listing (`document_get` and `document_list`) and execute handlers via protocol.
- [x] Global token redaction verifies all occurrences are replaced.
- [x] No Docker, background workers, or listening ports used.
- [x] All focused tests and TypeScript checks pass cleanly.

# Proposed Addendum: PDF and Google Drive Document Intake

## Status

**PROPOSED — awaiting Product Owner approval for general workflow.** This document is not part of
`SPEC.md` yet and does not authorize production deployment, live credentials, a new public webhook,
a background worker, or catalog writing.

### Authorized Read-Only Metadata Slice (2026-09-04)

The Product Owner has explicitly authorized a minimal, read-only implementation slice of Features 08 and 09:
- **Scope:** Read-only listing and inspection of document intake metadata via Management HTTP API (`GET /api/v1/documents`, `GET /api/v1/documents/{document}`), CLI (`document-intake`), and stdio MCP server (`document-intake-mcp`).
- **Rationale for Sequence:** This read-only adapter precedes extraction (Features 02–05) solely as an administrative visibility adapter so authorized external agents (Claude Code, Codex, Hermes, OpenClaw) can safely inspect uploaded document metadata. It is NOT a document-processing or extraction pipeline.
- **Strict Boundary:** No upload, extraction, OCR, candidate creation/editing, catalog drafts, publishing, deletion, raw file download, or direct DB/SQL access.
- **Unapproved Features:** Features 02–07 remain strictly **UNAPPROVED** and deferred to future decision gates.

## Goal

An authenticated Business Admin may submit a business document as a PDF, receive structured catalog
candidates backed by page evidence, review and edit those candidates, and explicitly create unpublished
catalog drafts. Google Drive is an optional later input channel to the same workflow.

## Ownership and boundaries

- Management/MySQL remains the source of truth for catalog, prices, availability, FAQ, policy, and knowledge.
- Chatwoot remains the source of truth for conversations and human handoff.
- A PDF or Drive file is source material, never a published catalog record by itself.
- AI output is untrusted candidate data until it passes deterministic validation and human review.
- The AI conversation service continues to access Management through authenticated HTTP APIs only; it never
  receives database credentials or direct SQL access.

## Proposed requirements

### Document source and extraction

- **FR-DOC-001:** A Business Admin can upload a bounded PDF into private Management storage.
- **FR-DOC-002:** The system validates the uploaded file's extension, MIME type, PDF signature, byte limit,
  page limit, and authorization before extraction.
- **FR-DOC-003:** Each source has an immutable SHA-256 hash, source type, status, retention metadata, and an
  authorized owner/audit reference.
- **FR-DOC-004:** Text-based PDFs are extracted page-by-page within bounded process, page, character, and
  timeout limits. A PDF with insufficient text is reported as `ocr_required`; it is not treated as a successful
  extraction with invented content.
- **FR-DOC-005:** Every structured candidate and field-level warning is traceable to source document version and
  page reference. The system records parser, prompt, schema, and model version where applicable.
- **FR-DOC-006:** Document text is untrusted data. It is separated from model instructions and is never logged
  as prompt or application log content.

### Candidate and draft safety

- **FR-DOC-007:** AI may create bounded catalog candidates only with allowlisted fields and strict JSON-schema
  validation. Missing or ambiguous fields are `null`/warnings, never guesses.
- **FR-DOC-008:** Viewing, editing, retrying, rejecting, or confirming a candidate requires authenticated admin
  authorization.
- **FR-DOC-009:** Preview, extraction, and candidate editing do not create or modify a published catalog item.
- **FR-DOC-010:** Explicit confirm creates new, unpublished catalog drafts only. Existing duplicate `code` values
  are skipped and never overwritten.
- **FR-DOC-011:** Publish remains a separate explicit human action in the existing catalog workflow.

### Google Drive and external agents

- **FR-DOC-012:** A later Google Drive manual-import feature downloads an authorized PDF into the same private
  intake path and records Drive file ID/version as source metadata. It does not create a second catalog path.
- **FR-DOC-013:** Folder auto-sync, Drive change webhooks, queue workers, and automatic updates/deletes are out
  of this addendum and require separate approval.
- **FR-DOC-014:** A later API/CLI/MCP surface may expose read and draft-only actions through scoped authorization.
  It must not expose publish, overwrite, arbitrary file access, SQL, or database credentials.
- **FR-DOC-014a (Authorized Read-Only Metadata Slice):** Authorized external agent clients (CLI `document-intake`,
  stdio MCP server `document-intake-mcp`) can list and inspect safe document intake source metadata over
  authenticated, rate-limited Management HTTP API endpoints (`GET /api/v1/documents`, `GET /api/v1/documents/{document}`)
  requiring token ability `documents:read`. Private storage paths, SHA-256 hashes, user IDs, raw files, and cancelled internals
  are strictly redacted or 404'd. No write, upload, extraction, draft creation, catalog modification, or raw file download is permitted.

## Proposed non-functional requirements

- **NFR-DOC-001:** Initial limits are 10 MB, 20 pages, 20 candidates, and a bounded extracted-text budget.
  Final numeric timeout and character limits are established by the local parser spike before Feature 1.
- **NFR-DOC-002:** PDF bodies, page text, raw LLM outputs, prompts, OAuth refresh tokens, and service tokens must
  not appear in application logs, browser responses, test fixtures, or source control.
- **NFR-DOC-003:** New database migrations are additive and have tested rollback paths. No destructive catalog
  migration is part of the feature.
- **NFR-DOC-004:** Any parser, AI-provider, Drive, validation, or authorization failure fails closed. It creates
  neither a catalog item nor an implied successful import.
- **NFR-DOC-005:** The MVP must run without Docker, Docker Compose, a new queue worker, Redis, or a public webhook.

## Proposed acceptance criteria

- **AC-DOC-001:** An authenticated admin can submit a valid synthetic text PDF and receive a source record without
  a public file URL.
- **AC-DOC-002:** Wrong MIME/signature, corrupt PDF, excessive bytes/pages, or unauthorized access is rejected
  without an orphan file or catalog write.
- **AC-DOC-003:** A text PDF fixture has bounded page-level extraction evidence; a low-text fixture returns
  `ocr_required`.
- **AC-DOC-004:** An extraction response with additional fields, invalid type/enum, invalid URL, invalid numeric
  value, or malicious document instructions cannot create a candidate or catalog record.
- **AC-DOC-005:** An admin can edit/reject candidates and explicitly confirm valid candidates into unpublished
  catalog drafts. Duplicate codes are skipped without modifying existing rows.
- **AC-DOC-006:** A draft created from a document never appears in catalog search, Flex cards, or AI answers before
  a human publishes it.
- **AC-DOC-007:** Existing Excel/CSV import and legacy package API contracts remain compatible.
- **AC-DOC-008:** No document body, prompt, raw model output, token, or credential appears in captured logs/tests.

## Explicit exclusions for this first approval

- OCR implementation and OCR-provider selection
- Google Drive OAuth, Picker, or automatic folder synchronization
- Drive webhooks, scheduled jobs, queue workers, Redis, or any new public endpoint
- Vector database, semantic retrieval, autonomous publishing, catalog overwrites, or direct SQL
- MCP server, Hermes/OpenClaw automation, or ChatGPT/Claude write access
- Docker image or Compose changes

## Required approval before Feature 1

Approve the requirements above and these recommended MVP decisions:

1. Digital/text PDFs first; scanned PDFs are reported as `ocr_required`.
2. Initial size/page/candidate limits are 10 MB / 20 pages / 20 candidates.
3. Each document may create multiple candidates.
4. Confirm creates drafts only; publish is human-only.
5. Duplicate `code` values are skipped and never overwritten.
6. Source PDFs use private storage with a retention value to be chosen before Feature 1.

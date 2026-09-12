# Feature 00 Review Packet — Document Intake Contract and Local Parser Spike

## Review status

- Status: **AWAITING_REVIEW**
- Requirement IDs: Proposed `FR-DOC-001` through `FR-DOC-014`, `NFR-DOC-001` through
  `NFR-DOC-005`, and `AC-DOC-001` through `AC-DOC-008`
- Reviewer: Product Owner
- Scope: Product contract, local-development parser decision, synthetic fixtures, and a local parser spike

## Scope completed

- Added a proposed addendum instead of editing the currently dirty canonical `SPEC.md`.
- Added ADR-001 proposing Poppler `pdfinfo`/`pdftotext` only for the local digital-PDF spike.
- Added synthetic Thai text, embedded-instruction, low-text, and invalid-file fixtures.
- Added a local-only spike script that generates temporary PDFs and validates page count/text extraction.
- Explicitly excluded Docker, Docker Compose, containers, workers, OCR, Google Drive, MCP, deployment, and
  credentials from this Feature.

## Changed files

- `docs/proposals/PDF_DRIVE_DOCUMENT_INTAKE_SPEC_ADDENDUM.md` — proposed requirements and boundaries
- `docs/decisions/ADR-001-digital-pdf-text-extraction.md` — parser decision and alternatives
- `apps/management/tests/Fixtures/Documents/*` — synthetic fixture inputs only
- `apps/management/scripts/document-intake-spike.sh` — local parser verification
- `docs/reviews/2026-09-04-feature-00-document-intake-contract.md` — this review evidence

## Database and migration effects

- None. No migration was created or run.
- No catalog, knowledge, import, user, or runtime data was changed.

## Configuration and runtime effects

- None. No environment variable, Composer dependency, Dockerfile, Compose file, service, queue, OCR provider,
  OAuth credential, or endpoint was changed.
- Local tools observed before the spike: `/opt/homebrew/bin/pdfinfo` and `/opt/homebrew/bin/pdftotext`.

## Verification evidence

| Command/Test | Result | Evidence/Notes |
|---|---|---|
| `apps/management/scripts/document-intake-spike.sh` | Passed | Verified a one-page synthetic Thai text PDF, preserved an embedded instruction as document data, identified a low-text fixture, and rejected a pseudo-PDF. It creates/removes only a `mktemp` directory. |
| Trailing-whitespace scan for Feature 00 files | Passed | No matches. The scan's nonzero search exit was expected because it found no trailing whitespace. |
| Docker/Compose commands | Not run by design | Product Owner explicitly requested no Docker on the 8 GB development machine. |
| PHP/Laravel/frontend tests | Not run | Feature 00 adds no production PHP/TypeScript code; Feature 1 will add focused tests. |

## Security and privacy review

- Fixtures are synthetic and contain no customer content, PII, token, or production identifier.
- The spike uses fixed fixture paths and quoted argument values; it does not interpolate a user filename into a
  shell command.
- The spike prints outcome labels only, not extracted document bodies.
- No source PDF is persisted: generated PDFs live only in a `mktemp` directory and are removed on exit.
- The future implementation must not copy this spike's shell behavior into a controller. It needs an adapter
  with explicit timeout, private storage authorization, and sanitized failure categories.

## Known limitations and unverified items

- The parser decision is validated only on the local macOS development toolchain until a later approved
  packaging test runs in the Linux application image.
- This is not an OCR quality test; a blank/low-text fixture only demonstrates the expected decision boundary.
- No approved production model capability test has been run.
- `SPEC.md` remains unchanged because it has pre-existing user changes and this addendum still awaits approval.
- No Drive OAuth, MCP client, Hermes/OpenClaw integration, or browser UI exists.

## Reviewer checklist

- [ ] Approve the proposed `FR-DOC`, `NFR-DOC`, and `AC-DOC` contract.
- [ ] Approve digital/text PDFs as the first supported format and `ocr_required` for scanned PDFs.
- [ ] Approve proposed initial limits: 10 MB, 20 pages, 20 candidates.
- [ ] Choose source-PDF retention duration before Feature 1.
- [ ] Approve ADR-001's Poppler approach for Feature 1/2 planning.
- [ ] Confirm that Catalog creation remains Draft-only and Publish remains human-only.
- [ ] Approve Feature 1 — Secure PDF Upload, or request changes.

# Retire admin document upload

Requirement: SPEC FR-DOC-RETIRE-001, approved 2026-09-11.

## Changes

- Removed the PDF navigation entry and frontend document route helpers.
- Removed `Documents/Index.tsx`, `Documents/Show.tsx`, `DocumentIntakeController.php` and `DocumentUploadRequest.php` from Management.
- Replaced admin document routes with an authenticated/admin-only 410 retirement response for the path and descendants, for all HTTP methods. No model binding or file operations run.
- Replaced obsolete `DocumentUploadTest.php` with `DocumentRetirementTest.php`, covering retired read/write endpoints, no new uploads, preserved existing sources/files, navigation and staff authorization.
- Updated SPEC, Management README, historical DOCUMENT_INTAKE.md and the proposal skill. External AI clients read PDFs and submit structured data; Management no longer accepts admin PDF uploads.

## Preservation and recovery

No database migration, stored-file deletion, evidence cleanup, token change, commit, push or production deployment was performed. Existing document metadata read APIs/MCP and evidence models remain compatible.

The five removed source/test files were backed up before removal in local temporary archive `/tmp/management-document-retirement.n3hTGI/retired-source.tar`. This is a temporary recovery copy, not a permanent backup. Their review-VM copies were renamed with `.retired` suffixes outside active PHP/TS test/build discovery. Production was untouched.

## Verification

TDD: retirement tests first failed on active 200/302 responses and visible navigation, then passed after implementation. Tests/build run only in the existing isolated GCP review workspace, using SQLite memory databases, composer:2 and node:22-alpine. No Docker on Mac.

Final results: full Management suite 198 tests / 938 assertions passed (real `*Test.php`
files selected, excluding AppleDouble `._*` files). `npm run typecheck`, `npm run lint`
and `npm run build` passed. Build retains the existing >500 kB chunk warning; JS output
684.69 kB / 206.88 kB gzip. `git diff --check` passed. No live/browser verification or
production deployment is claimed.

# Feature 01 Review Packet — Secure PDF Upload and Document Source Lifecycle

## Review status

- **Status:** **AWAITING_REVIEW**
- **Requirement IDs:** `FR-DOC-001`, `FR-DOC-002`, `FR-DOC-003`, `FR-DOC-008`, `FR-DOC-009`, `NFR-DOC-001`, `NFR-DOC-002`, `NFR-DOC-003`, `NFR-DOC-004`, `NFR-DOC-005`, `AC-DOC-001`, `AC-DOC-002`, `AC-DOC-008`
- **Reviewer:** Codex
- **Date:** 2026-09-04

---

## Scope completed

### Implemented in Feature 1:
1. **Additive Database Migration:** Created `document_sources` schema storing owner user ID, source type, sanitized original filename, private storage disk/path, SHA-256 hash, MIME type, byte size, status, sanitized failure reason, and metadata. No `expires_at` column is added (retention policy is unresolved in Version 1).
2. **Deterministic File Validation:**
   - Case-insensitive `.pdf` extension check.
   - `application/pdf` MIME type verification.
   - Binary `%PDF-` magic byte signature check at byte offset 0.
   - Maximum 10 MB (10,240 KB) byte size bound and non-empty (> 0 bytes) check.
   - Path traversal sanitization stripping directory components (`../`, `..\\`) and illegal characters.
   - SHA-256 cryptographic digest recorded as immutable provenance for audit and verification.
3. **Private Storage Isolation:**
   - Files are stored in the private `local` disk (`storage/app/private/document-sources/`).
   - Files are assigned random UUID names (`{uuid}.pdf`).
   - Zero public URLs or symbolic links to `public/`.
4. **Lifecycle & Status Model:**
   - Tracked statuses: `uploaded`, `extracting`, `ready`, `ocr_required`, `failed`, `cancelled`.
   - Sanitized failure categories (`invalid_pdf`, `empty_file`, `oversized`, `security_rejected`, `processing_error`).
5. **Truthful Cancellation & Failure Cleanup:**
   - Admin cancellation calls `Storage::disk('local')->delete($path)`.
   - If `Storage::delete` returns `false` or throws an exception, the document is **not** marked cancelled; a truthful failure error is reported to the user and logged without sensitive inputs.
   - Only when file deletion succeeds is the `document_sources` status updated to `cancelled`.
   - On upload error after storing the temporary file, cleanup safely handles a false/throwing delete without claiming success and logs no sensitive file content.
6. **Inertia Admin UI:**
   - Added Document Intake navigation item to `AdminLayout.tsx`.
   - Upload & listing view (`Documents/Index.tsx`) with file status badges, formatted sizes, SHA-256 snippets, and cancellation controls.
   - Detail view (`Documents/Show.tsx`) displaying provenance, private storage audit, and cancellation controls.
7. **Strict Admin Authorization:** Guarded by `DocumentSourcePolicy` ensuring unauthenticated and non-admin users cannot upload or view documents.
8. **Catalog Safety:** Zero records created or mutated in `packages` or `knowledge_entries`.

### Intentionally Excluded from Feature 1:
- `DocumentIntakeCleanupCommand`, retention commands, schedulers, or automated pruning (retention policy remains unresolved).
- Duplicate-hash blocking / deduplication query (non-atomic read-before-write omitted from Feature 1; SHA-256 is kept for provenance).
- Text extraction and parser invocation (deferred to Feature 2).
- OCR engine and scanned document extraction (deferred to Feature 5).
- LLM candidate extraction and OpenRouter API integration (deferred to Feature 3).
- Candidate review and Catalog Draft creation (deferred to Feature 4).
- Google Drive OAuth, Picker, and auto-sync (deferred to Feature 6 & 7; Google Drive source type is model-neutral with no UI or behavior).
- Public APIs, CLI extraction commands, or MCP tools (deferred to Feature 8 & 9).

---

## Changed files

| File | Change Type | Rationale |
|---|---|---|
| `apps/management/database/migrations/2026_09_04_000001_create_document_sources_table.php` | Added | Additive schema for document source metadata, storage path, SHA-256, and lifecycle status (no `expires_at`) |
| `apps/management/app/Models/DocumentSource.php` | Added | Eloquent model with status/failure constants, casts, scopes, and user relationship |
| `apps/management/app/Policies/DocumentSourcePolicy.php` | Added | Single-tenant admin authorization policy matching `PackagePolicy` |
| `apps/management/app/Http/Requests/DocumentUploadRequest.php` | Added | Validation for extension, MIME, magic signature (`%PDF-`), 10 MB limit, and traversal sanitization |
| `apps/management/app/Http/Controllers/Admin/DocumentIntakeController.php` | Added | Admin controller handling `index`, `store`, `show`, `cancel`, and `destroy` with truthful deletion validation |
| `apps/management/app/Http/Resources/DocumentSourceResource.php` | Added | Standardized API resource for Inertia pagination |
| `apps/management/routes/web.php` | Modified | Registered admin routes under `Route::middleware('auth')->prefix('admin')` |
| `apps/management/resources/js/types/index.d.ts` | Modified | Added `DocumentSourceStatus` and `DocumentSource` TypeScript interfaces |
| `apps/management/resources/js/lib/routes.ts` | Modified | Added admin route builders for document index, store, show, cancel, destroy |
| `apps/management/resources/js/components/AdminLayout.tsx` | Modified | Added `เอกสารนำเข้า (PDF)` navigation link with `FileText` icon |
| `apps/management/resources/js/pages/Documents/Index.tsx` | Added | Inertia admin page for upload, validation feedback, and document history table |
| `apps/management/resources/js/pages/Documents/Show.tsx` | Added | Inertia admin page for metadata, private storage audit, and cancellation |
| `apps/management/tests/Feature/DocumentUploadTest.php` | Added | 14 focused tests covering auth, validation, security, storage isolation, upload cleanup, and truthful cancel failures |
| `apps/management/docs/DOCUMENT_INTAKE.md` | Added | Technical and operational documentation for document intake architecture |
| `apps/management/README.md` | Modified | Documented document intake feature and routes |
| `docs/reviews/2026-09-04-feature-01-secure-pdf-upload.md` | Added | This review packet |

---

## Database and migration effects

- **New Tables:** `document_sources`
  - Columns: `id`, `user_id`, `source_type`, `original_filename`, `storage_disk`, `storage_path`, `file_hash`, `mime_type`, `file_size`, `page_count`, `status`, `failure_reason`, `meta`, `created_at`, `updated_at`.
  - Indexes: `file_hash`, compound index `['status', 'created_at']`.
- **Existing Data Impact:** None. Zero existing tables altered.
- **Rollback Verification:** Reversible down migration verified in test:
  ```php
  $migration->down(); // Schema::dropIfExists('document_sources')
  ```

---

## API and configuration changes

- **Protected Admin Web Routes:**
  - `GET /admin/documents` (`admin.documents.index`)
  - `POST /admin/documents` (`admin.documents.store`)
  - `GET /admin/documents/{document}` (`admin.documents.show`)
  - `POST /admin/documents/{document}/cancel` (`admin.documents.cancel`)
  - `DELETE /admin/documents/{document}` (`admin.documents.destroy`)
- **Environment Variables:** No new environment variables required. Uses default private `local` disk.
- **Dependencies:** Zero Composer or npm dependencies added.

---

## Verification evidence

| Command / Test | Result | Evidence / Notes |
|---|---|---|
| `php artisan test tests/Feature/DocumentUploadTest.php` | **Passed** | 14 tests, 91 assertions in 710ms. Tests unauthenticated redirect, non-admin 403, valid PDF upload, fake PDF signature rejection, wrong extension, empty file, oversized file (> 10MB), path traversal sanitization, cancel file deletion, show page Inertia render, cancel failure when `Storage::delete` returns false, cancel failure when `Storage::delete` throws, upload failure cleanup, and migration down/up rollback. |
| `php artisan test tests/Feature/ImportTest.php ...` | **Passed** | 47 regression tests, 132 assertions in 1.19s across all core management features (imports, flex messages, package validation, catalog search, business profile, knowledge entries, image upload, database seeder). |
| `php artisan test tests/Unit/ApiTokenTest.php ...` | **Passed** | 6 tests, 9 assertions in 234ms. |
| `npm run typecheck` | **Passed** | TypeScript `tsc --noEmit` exited 0 with zero errors. |
| `npm run lint` | **Passed** | ESLint exited 0 with zero warnings or errors. |
| `npm run build` | **Passed** | Vite production bundle built in 2.53s. |

---

## Security and privacy review

1. **Authentication & Authorization:**
   - Upload and detail routes require session authentication and admin role (`is_admin = true`).
   - Guest requests are redirected to `/login`; non-admin users receive 403 Forbidden.
2. **Input Validation & Traversal Defense:**
   - Filenames are sanitized using `basename()` and regex unicode character filtering.
   - Storage filenames use random UUIDs; client filenames never dictate server filesystem paths.
3. **Private File Storage:**
   - PDF files are stored in `storage/app/private/document-sources/` on disk `'local'`.
   - No public URLs, static web symlinks, or direct HTTP downloads are exposed.
4. **Data Isolation & Catalog Protection:**
   - No records in `packages` or `knowledge_entries` are created or altered.
   - Document upload cannot bypass draft or publishing workflows.
5. **Zero Body / PII Logging:**
   - PDF binary bodies, text content, and customer PII are strictly omitted from application logs.
   - Failures log only sanitized categories and error classes.

---

## No-Docker confirmation

As mandated by hard constraints:
- **No Docker, Docker Compose, Podman, or container runtime was started, executed, or modified.**
- **No background service, queue worker, Redis instance, or deployment pipeline was executed.**
- All validation and testing were performed safely in local process mode on an 8 GB development machine.

---

## Known limitations and risks

1. **Synchronous Upload:** Feature 1 handles PDF upload synchronously in the HTTP request. With the 10 MB bound, memory and execution times remain well within PHP defaults, but text extraction in Feature 2 must respect explicit timeouts.
2. **Retention Policy Unresolved:** Per product owner boundaries, retention policy duration and cleanup automation are unresolved and not included in Feature 1.

---

## Reviewer checklist (AWAITING_REVIEW)

- [ ] `FR-DOC-001` satisfied: Admin can upload bounded PDF into private storage.
- [ ] `FR-DOC-002` satisfied: Strictly validates extension, MIME, `%PDF-` signature, and 10 MB limit.
- [ ] `FR-DOC-003` satisfied: SHA-256 hash, sanitized filename, and status lifecycle tracked.
- [ ] `FR-DOC-008` satisfied: Admin authorization enforced on all document routes.
- [ ] `FR-DOC-009` satisfied: Zero Catalog (`packages`) or Knowledge (`knowledge_entries`) writes.
- [ ] Truthful cancellation verified: `cancel` fails and preserves non-cancelled state if `Storage::delete` returns false or throws.
- [ ] `expires_at` and retention commands excluded.
- [ ] `NFR-DOC-001` - `NFR-DOC-005` satisfied: Limits enforced, private storage isolated, no Docker used.
- [ ] Additive migration and rollback verified.
- [ ] Tests and typecheck pass cleanly.
- [ ] Ready for Feature 1 review and decision to proceed to Feature 2.

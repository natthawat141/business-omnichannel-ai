# Document Intake Architecture and Operational Specification

> Retired 2026-09-11, SPEC FR-DOC-RETIRE-001. The admin upload/detail/lifecycle UI and
> write endpoints have been removed. Old admin URLs return 410. Existing private files,
> source records and metadata read APIs are retained; no deletion or migration is performed.
> External clients read PDFs and submit structured proposals instead. The sections below
> are historical documentation, not instructions for the current application.

## 1. Overview and Scope

Document Intake is an admin-only ingestion pipeline designed to allow property and business managers to introduce unstructured or semi-structured business files (primarily PDFs) into the system safely.

In **Feature 1 (Secure PDF Upload and Document Source Lifecycle)**, the system manages the secure receipt, validation, private storage, and lifecycle tracking of source PDF documents without invoking LLMs, without performing OCR, and without writing to the Catalog (`packages`) or Knowledge (`knowledge_entries`) tables.

```
+-----------------------------------------------------------------------------------+
| Management Web UI (Admin Only)                                                    |
|   - Authenticated Admin submits .pdf via /admin/documents                         |
+-----------------------------------------------------------------------------------+
                                         |
                                         v
+-----------------------------------------------------------------------------------+
| DocumentUploadRequest (Validation Guard)                                          |
|   - Admin Gate Authorization ('create', DocumentSource::class)                    |
|   - File Extension: strictly .pdf                                                 |
|   - MIME Type: strictly application/pdf                                           |
|   - Magic Bytes: starts with %PDF- at offset 0                                    |
|   - Size Bound: <= 10 MB (10,240 KB), > 0 bytes                                   |
|   - Path Traversal Sanitization: basename() + regex sanitization                  |
|   - Provenance: SHA-256 cryptographic digest recorded for audit                   |
+-----------------------------------------------------------------------------------+
                                         |
                                         v
+-----------------------------------------------------------------------------------+
| DocumentIntakeController                                                          |
|   - Stores file in private disk ('local' -> storage/app/private/document-sources) |
|   - Creates DocumentSource record with status 'uploaded'                          |
|   - Zero Catalog / Knowledge records created                                      |
+-----------------------------------------------------------------------------------+
```

---

## 2. Security and Storage Architecture

### 2.1 Private Local Storage Isolation
- Uploaded files are written to the `local` filesystem disk (`storage_path('app/private')`).
- Files are assigned random UUID filenames (`document-sources/{uuid}.pdf`).
- **Zero Public URL Exposure:** Private PDF files are never linked under `public/` or `storage/app/public`.
- Non-admins and unauthenticated users are strictly blocked by policy (`DocumentSourcePolicy`).

### 2.2 Strict File Validation
Every uploaded document must satisfy:
1. **Extension Check:** Case-insensitive match against `.pdf`.
2. **MIME Type:** Must report `application/pdf`.
3. **Magic Byte Signature:** The first 5 bytes of the binary stream must equal `%PDF-`.
4. **File Size Bounds:** Greater than 0 bytes and no larger than 10,240 KB (10 MB).
5. **Filename Sanitization:** Strips directory traversal components (`../`, `..\\`) and restricts original name to safe unicode characters up to 255 characters.
6. **SHA-256 Provenance:** Computes and records the SHA-256 cryptographic digest of the file for immutable provenance and audit verification.

---

## 3. Document Source Lifecycle and Statuses

The `document_sources` table tracks the end-to-end provenance of each source:

| Status | Description | Feature Scope |
|---|---|---|
| `uploaded` | File verified and stored in private storage | **Feature 1** |
| `extracting` | Digital text extraction in progress | Feature 2 |
| `ready` | Text extracted and candidate analysis ready | Feature 2 / 3 |
| `ocr_required` | Scanned or low-text document requiring OCR | Feature 2 / 5 |
| `failed` | Processing failure with sanitized reason category | Feature 1+ |
| `cancelled` | Document cancelled by admin; private file deleted | **Feature 1** |

### Failure Categories
To prevent leaking sensitive document content or internal server paths into logs or responses, failures are classified into deterministic categories:
- `invalid_pdf`: Failed PDF signature or syntax checks.
- `empty_file`: 0-byte file.
- `oversized`: File exceeded the 10 MB bound.
- `security_rejected`: Path traversal or disallowed file format.
- `processing_error`: Internal storage or database failure.

---

## 4. Cancellation and Failure Cleanup

### 4.1 Truthful Cancellation
Admins can cancel any active document source via `POST /admin/documents/{id}/cancel` or `DELETE /admin/documents/{id}`:
- The private file stored on disk is deleted via `Storage::disk('local')->delete($path)`.
- If the file deletion returns `false` or throws an exception, the document is **not** marked cancelled; a truthful failure message is returned to the user and logged without sensitive content.
- Only upon successful disk deletion is the `document_sources` record status updated to `cancelled`.

### 4.2 Upload Failure Cleanup
- If an exception occurs after storing a file during upload, cleanup is performed immediately.
- If deletion during cleanup throws or returns false, it is logged safely without sensitive data, and the upload fails closed.
- Automatic retention scheduling or pruning commands are not part of Feature 1; retention policy remains unresolved.

---

## 5. Non-Negotiable Boundaries

1. **No Catalog Overwrite or Auto-Publish:** Ingesting a PDF creates only a `DocumentSource` record. No row in `packages` or `knowledge_entries` is touched.
2. **No Docker or Service Dependencies:** Runs entirely on standard PHP/Laravel file storage without requiring Docker, background queue workers, or Redis.
3. **No Content Logging:** File contents, text snippets, and PII are never logged to `laravel.log`. Only metadata (ID, hash, size, status, failure category) is logged.

---

## 6. External Agent Read-Only API (Feature 08/09 Slice)

Authorized external agents (Claude Code, Codex, Hermes, OpenClaw) can inspect intake metadata using authenticated Management HTTP API endpoints or local stdio tools.

### 6.1 Endpoints and Authorization
- `GET /api/v1/documents`
- `GET /api/v1/documents/{document}`
- **Authentication**: Requires a revocable bearer token with the distinct ability `documents:read`. Tokens with generic `read` ability are rejected with HTTP 403 Forbidden.
- **Rate Limiting**: Throttled at 60 requests per minute per token.

### 6.2 Safe Allowlisted Metadata Response
Both endpoints expose **only** safe metadata:
- `id` (integer)
- `source_type` (string, e.g. `'upload'`)
- `original_filename` (sanitized string)
- `mime_type` (string, e.g. `'application/pdf'`)
- `file_size` (integer bytes)
- `status` (string, allowlisted: `uploaded`, `extracting`, `ready`, `ocr_required`, `failed`)
- `page_count` (integer or null)
- `failure_category` (sanitized enum category or null; server maps known enums and safely collapses any unknown or raw text to `processing_error`, never exposing `failure_reason`)
- `created_at` / `updated_at` (ISO 8601 strings)

Pagination bounds: `limit` is bounded to `1..50` (default 15); `page` is bounded to `1..1000` (default 1).

### 6.3 Explicit Redaction and 404 Semantics
- **Strictly Excluded**: Private storage paths (`storage_path`, `storage_disk`), SHA-256 digests (`file_hash`), internal metadata blobs (`meta`), user/owner identities (`user_id`, `user`), raw PDF bytes, and exception traces are completely omitted from responses.
- **Cancelled Document Semantics**: Cancelled documents represent purged files and private internal state. `GET /api/v1/documents/{document}` returns HTTP 404 Not Found for any cancelled document, and list queries strictly filter them out.
- **No Publish / No Catalog Writes**: External agents have zero ability to create drafts, publish catalog items, update existing records, or download raw files.

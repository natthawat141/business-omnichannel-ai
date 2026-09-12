# Aion3 Knowledge Management

Admin AI setup and short-lived document keys: [AI Setup guide](docs/AI_SETUP.md).
Staff accounts, roles and private password setup: [User management](docs/USER_MANAGEMENT.md).
Development-only typed catalog slice and migration limits: [Typed catalog](docs/TYPED_CATALOG.md).

Optional project/layout profiles, agent filters and COCO PARC review fixture: [Catalog profiles](docs/CATALOG_PROFILES.md).

Image-first package editor, map links, responsive review table and portable deployment recommendation:
[Editor review](../../docs/reviews/2026-09-11-package-editor-map.md).

A low-cost, single-deploy **Laravel modular monolith** that manages catalog items, FAQs,
business profile, and free-form knowledge entries. The current admin experience is lean
real-estate-first and labels catalog items as properties/listings, while the existing `packages`
storage and compatibility routes remain domain-neutral. It exposes a small authenticated
**read API** for the Python AI orchestrator to consume.

The UI is a **lightweight internal admin tool** built with **Laravel + Inertia + React
(TypeScript)** — a single deploy, no separate SPA. There is no public/customer-facing site; the
only outward boundary is the authenticated compact **read API** consumed by `services/ai`.

- **Backend:** Laravel 13, PHP 8.3+
- **Frontend:** React 19 + TypeScript, Inertia 2, Tailwind CSS 4, Vite
- **Database:** MySQL (production). SQLite is used only for tests/dev convenience.
- **Excel:** maatwebsite/excel (XLSX import/export)
- **No app-owned queue/Redis, no vector DB, no separate SPA** — the Management app can run alone
  for focused development and is also packaged inside the repository's root Docker Compose stack.

---

## 1. Requirements (Windows, non-Docker)

| Tool | Version | Notes |
|------|---------|-------|
| PHP | **8.3+** | required extensions below |
| Composer | 2.x | dependency install |
| Node.js | 20+ | with npm |
| MySQL | 8.0+ (or MariaDB 10.6+) | production database |

**Required PHP extensions:** `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`,
`json`, `bcmath`, `fileinfo`, `curl`, and **`zip` + `gd`** (needed by maatwebsite/excel / PhpSpreadsheet).

On Windows the simplest route is Laravel Herd, XAMPP (PHP 8.3 build), or the standalone PHP 8.3
zip. Enable the extensions above in `php.ini`.

---

## 2. First-time setup

```powershell
# From line-bot-management/
# inertiajs/inertia-laravel and maatwebsite/excel were added to composer.json after the
# committed lock file, so resolve them explicitly the first time:
composer update inertiajs/inertia-laravel maatwebsite/excel --with-all-dependencies
# (subsequent installs are just: composer install)

copy .env.example .env
php artisan key:generate
```

Then edit `.env`:

```dotenv
APP_NAME="Aion3 Knowledge Management"
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=line_knowledge
DB_USERNAME=root
DB_PASSWORD=your-password

# Seed exactly ONE admin (never commit a real password)
ADMIN_NAME="Administrator"
ADMIN_EMAIL=admin@yourdomain.com
ADMIN_PASSWORD=choose-a-strong-password
```

Create the database, then migrate + seed:

```powershell
# In MySQL: CREATE DATABASE line_knowledge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate --seed
```

The seeder creates only the admin from the `ADMIN_*` env values and skips safely when
`ADMIN_PASSWORD` is blank. Business records must be entered or imported by the business owner.

Build the frontend:

```powershell
npm install
npm run build
```

---

## 3. Development

```powershell
# Terminal 1 — Laravel
php artisan serve

# Terminal 2 — Vite (HMR)
npm run dev
```

Visit `http://localhost:8000` for the public page and `http://localhost:8000/login` for the admin.

Front-end quality gates (no PHP needed):

```powershell
npm run typecheck   # tsc --noEmit
npm run lint        # eslint
npm run build       # production bundle
```

Back-end tests (needs PHP 8.3+; uses an in-memory SQLite DB, no MySQL required):

```powershell
php artisan test
```

---

## 4. Admin features

Session-authenticated admin area at `/admin` (login throttled: 5 attempts per email+IP, plus a
route-level throttle). Full CRUD with search / filter / pagination / delete-confirm / flash
messages for:

- **ประเภททรัพย์** and **รายการทรัพย์** — TH/EN names and descriptions, category, sale/rent,
  availability, location, project, rooms, area, floor, price, terms, keywords, active/published,
  effective date window, and one HTTPS primary image
- **FAQs** — TH (and optional EN) question/answer, category, tags, active
- **Knowledge entries** — title, body (plaintext/Markdown), type, category, tags, source URL, version, reviewed_at, active
- **Answer analytics** — interaction metadata, daily/total counts, success rate, and response type without storing channel user IDs

### Primary property image upload

The property form accepts a local JPEG, PNG, or WebP file up to 10 MB. Laravel requests a short-lived
provider upload URL and the browser uploads directly to Cloudflare Images or R2. Provider secrets never
reach the browser; an R2 presigned URL necessarily identifies its access key ID. The property stores only
the resulting public delivery URL after the admin submits the form.

Configure the Management runtime with:

```dotenv
CLOUDFLARE_IMAGES_ACCOUNT_ID=
CLOUDFLARE_IMAGES_API_TOKEN=
CLOUDFLARE_IMAGES_DELIVERY_BASE_URL=https://imagedelivery.net/<account-hash>
CLOUDFLARE_IMAGES_VARIANT=public
CLOUDFLARE_IMAGES_DIRECT_UPLOAD_EXPIRY_MINUTES=10
```

The token requires **Account > Cloudflare Images > Edit**. Keep it in the deployment environment,
never in Vite variables or browser code. The development MCP connection is not a runtime credential
for Laravel. Cloudflare's workflow is documented in
[Accept user-uploaded images](https://developers.cloudflare.com/images/storage/upload-images/direct-creator-upload/).

To use Cloudflare R2 instead, create a bucket-scoped Object Read & Write S3 credential, connect the
bucket to a production custom domain, and set bounded CORS for the Management origin. Do not enable
the `r2.dev` URL for production.

```dotenv
PROPERTY_IMAGE_UPLOAD_DRIVER=r2
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_PUBLIC_BASE_URL=https://images.example.com
R2_DIRECT_UPLOAD_EXPIRY_MINUTES=10
```

The backend signs one object path and exact content type for a short-lived `PUT`; it never returns the
R2 secret access key. The access key ID appears only in the standard `X-Amz-Credential` signer field of the
presigned URL. Apply `infra/cloudflare/r2-cors.json` to the selected bucket after reviewing
the production origin. R2 presigned upload URLs use the S3 endpoint while public image reads use the
custom domain.

Lean Version 1 does not automatically delete an uploaded image when the admin replaces the URL,
removes the property, or leaves the form without saving. Those hosted-image lifecycle controls are
tracked as later storage management work.

---

## 5. XLSX import / export

คู่มือรูปแบบไฟล์ฉบับเต็มพร้อมชนิดข้อมูลและตัวอย่าง CSV: [`docs/IMPORT_FORMAT.md`](docs/IMPORT_FORMAT.md)

Under **นำเข้ารายการทรัพย์** (`/admin/imports`) for property-style catalog entries:

- Download template → empty XLSX with the exact heading row.
- Export → back up current property/package data as XLSX.
- Preview → upload `.xlsx/.xls/.csv` (max 5 MB) and review new, duplicate, and invalid rows before writing.
- Confirm → add new codes as unpublished drafts. Existing codes are skipped and never overwritten.
- The new 24-column format adds `attributes` JSON; both legacy 9-column and previous 23-column headers remain supported.
- FAQs and knowledge entries are managed through their web forms only.

**Column headings (snake_case, first row):**

| Resource | Columns |
|----------|---------|
| property-style imports | `code,category_slug,transaction_type,availability,name_th,description_th,price,sale_price,location_text,province,district,subdistrict,project_name,bedrooms,bathrooms,usable_area_sqm,land_area_sqw,floor,primary_image_url,effective_from,effective_until,terms,keywords,attributes` |
| legacy 9-column imports | `code,name_th,description_th,price,sale_price,effective_from,effective_until,terms,keywords` |

`code` and `name_th` are required. Dates use `YYYY-MM-DD`.

---

## 6. Document intake (retired)

Detailed operational specification: [`docs/DOCUMENT_INTAKE.md`](docs/DOCUMENT_INTAKE.md)

The `/admin/documents` UI, upload, detail and cancellation/deletion endpoints are retired
under FR-DOC-RETIRE-001. Old admin requests return 410. Existing private files and source
records are preserved, and metadata read APIs remain compatible. Give the PDF to the
external AI client and submit structured changes through the proposal MCP/CLI instead.

---

## 7. Knowledge read API (for the Python AI service)

Read-only, active-data-only JSON under `/api/v1`, guarded by a **revocable, hashed bearer token**
(only a SHA-256 hash is stored; the plaintext is shown once) and rate-limited (120 req/min).

### Issue / manage tokens

Via CLI:

```powershell
php artisan api-token:issue "chatwoot-ai-service" --expires=365
php artisan api-token:list
php artisan api-token:revoke 3
```

Or in the admin UI at `/admin/api-tokens` (the plaintext is shown once on creation).

### Endpoints

| Method | Path | Filters |
|--------|------|---------|
| GET | `/api/v1/meta` | active counts, `last_updated`, `schema_version` |
| GET | `/api/v1/packages` | `category`, `updated_since` (active **+ published + in-window** only) |
| GET | `/api/v1/faqs` | `category`, `updated_since` |
| GET | `/api/v1/knowledge` | `type`, `category`, `updated_since` |
| GET | `/api/v1/business-profile` | singleton business identity, hours, contact, tone, escalation topics |
| POST | `/api/v1/catalog/search` | validated bounded catalog filters; returns active + published + in-window + available only |
| GET | `/api/v1/catalog/{id}` | eligible catalog-item detail |
| POST | `/api/v1/flex/carousel` | `{"item_ids":[...]}`; exact ordered IDs, maximum 10, eligibility rechecked |
| GET | `/api/v1/flex/carousel` | compatibility/manual discovery by `category_slug` and `limit`; not used for exact AI search results |
| GET | `/api/v1/flex/catalog/{id}` | one eligible property Flex bubble |
| GET | `/api/v1/flex/{loan\|consignment\|about}` | fixed business-service Flex cards |

Legacy knowledge responses use a stable version 1.0 envelope:

```json
{ "meta": { "generated_at": "...", "count": 12, "version": "1.0" }, "data": [ ... ] }
```

Catalog search/detail responses use a version 1.1 `meta` + `data` contract. Flex endpoints return
LINE Flex JSON directly (`type`, `altText`, `contents`) rather than the knowledge envelope.

Example:

```bash
curl -X POST https://your-host/api/v1/catalog/search \
  -H "Authorization: Bearer lk_xxxx.yyyy" \
  -H "Content-Type: application/json" \
  -d '{"category_slug":"condo","transaction_type":"sale","limit":10}'
```

See `docs/ARCHITECTURE.md` for the module map and data contracts.

---

## 7. Security notes

- Never commit `.env`, tokens, or LINE/OpenRouter secrets. `.env.example` holds placeholders only.
- Admin passwords are seeded from env, hashed with bcrypt; API tokens are stored as SHA-256 hashes.
- Catalog search, detail, and property Flex responses require active, published, in-window, and
  `available` records. The legacy `/packages` contract remains backward compatible and applies its
  documented active/published/effective rules.
- `primary_image_url` must be HTTPS. The direct-upload endpoint is admin-only, rate-limited, and
  returns a one-time URL without exposing the Cloudflare API token. Empty property specifications
  use neutral copy rather than invented features.
# Remote AI connections

Simple browser-authorized MCP setup is at `/admin/ai-setup`; local CLI/key setup
remains at `/admin/ai-setup?advanced=1`. Public guide: `/docs/remote-mcp`.
Remote access is disabled by default until explicitly configured. See
[REMOTE_MCP.md](docs/REMOTE_MCP.md) for migrations, signing keys, scopes, lifecycle,
verification and production enablement gates. No AI tool can approve/apply/publish.

# Management Architecture

## Responsibility

`apps/management` is the system of record for business data. Laravel serves the internal Inertia/React admin UI and authenticated JSON APIs from one application.

```text
Business admin -> Laravel/Inertia -> MySQL
Business admin browser -> one-time upload URL -> Cloudflare Images or R2
Python AI service -> authenticated /api/v1 -> Laravel -> MySQL
```

Chatwoot owns conversations and human assignment. The Python service owns orchestration. Neither service connects directly to the Management database.

## Modules

| Area | Key files |
|---|---|
| Authentication | `app/Http/Controllers/Auth`, `app/Http/Requests/Auth` |
| Admin CRUD | `app/Http/Controllers/Admin`, `app/Http/Requests`, `app/Policies` |
| Business models | `app/Models` |
| Read API | `routes/api.php`, `app/Http/Controllers/Api/KnowledgeApiController.php`, `BusinessProfileApiController.php`, `CatalogSearchController.php`, `FlexMessageApiController.php` |
| Agent proposals | `app/Http/Controllers/Api/Agent/AgentChangeApiController.php`, `app/Services/Agent/AgentChangeService.php`, `AgentChangeSet`/revision/source models; admin review at `AgentChangeController` |
| API tokens | `app/Console/Commands/ApiToken*`, `app/Http/Middleware/AuthenticateApiToken.php` |
| Import/export | `app/Imports`, `app/Exports` |
| Primary image upload | `app/Services/CloudflareImages.php`, `app/Services/R2PropertyImages.php`, `PropertyImageUploadController.php` |
| Admin UI | `resources/js` |

## Data contract

- Optional `map_url` is an HTTPS map/share link (2048 characters maximum), independent of
  category and profile. Admin forms, PackageResource and staff agent proposals carry it;
  omission preserves a saved value and null clears it. There is no server-side URL fetch,
  iframe, geocoding or new inventory filter. Existing spreadsheet columns remain unchanged
  and do not export/import this new field. See the package editor review for rollout gates.

- Structured records are the source of truth for identity, price, status, publication, and effective dates.
- Legacy package reads return active, published, in-window records. Catalog search/detail and property
  Flex endpoints additionally require `availability = available`.
- Machine clients use revocable hashed bearer tokens.
- `POST /api/v1/catalog/search` accepts only allowlisted bounded filters. The AI never sends SQL or database fields.
- `POST /api/v1/flex/carousel` accepts 1–10 distinct `item_ids`, preserves their order, and independently
  drops inactive, unpublished, expired, or unavailable records before rendering.
- A catalog item may expose one validated HTTPS `primary_image_url`; it becomes the Flex hero image.
  An authenticated admin can request a rate-limited, short-lived Cloudflare Images POST or R2 presigned
  PUT URL. Laravel holds provider secrets, the browser uploads directly, and only the public delivery URL
  is stored. The R2 access key ID appears in the standard signed query while its secret remains server-side.
  R2 requires a bucket-scoped credential, bounded CORS and a production custom domain.
- Replaced, abandoned, and deleted-record images are not automatically removed from hosted storage in lean
  Version 1; multi-image galleries and hosted-image lifecycle management remain out of scope.
- The 24-column typed-attributes import, previous 23-column property import and legacy 9-column import create unpublished drafts and
  never overwrite duplicate codes.
- The development [typed catalog slice](TYPED_CATALOG.md) adds category schema versions and common
  attribute validation. The v0.2 proposal path may create only human-reviewed drafts; it does not enable direct agent application or publishing. Production activation is pending review.
- `/api/v1/agent` is separated from Chatwoot's customer-facing read API. Scoped agents can discover a bounded schema, read allowlisted records, preview, submit, and view only their own immutable change set. Admin session + CSRF is the only approve/apply path. After reviewing each proposal, an admin may select up to 25 already-approved sets and apply them in one transaction. A conflict rolls back every selected business write, marks the failing set for review, and never publishes newly created records. No model receives SQL or database credentials.
- These compatibility and Version 1 rules are governed by the root `SPEC.md`.

No clinic, school, booking, payment, or direct-LINE sample data is seeded.

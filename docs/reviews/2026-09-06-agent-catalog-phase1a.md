# Review: agent-managed catalog Phase 0 / 1A

Date: 2026-09-06. Implementer/reviewer: Codex. Status: **partial plan delivered for review;
production and next slice paused for product-owner direction on an existing contract mismatch**.

## Scope

SPEC FR-AGENT-001/002/007 partial; foundation for FR-CAT-003/004. TDD used: first new run failed
17 of 23 tests (missing typed values, schema persistence and unknown-key protection), then the
implementation was added. Further import, compatibility, schema conflict and role tests preceded fixes.
No agy delegation, Git commit/push, production migration/deployment, live key issuance or local Docker.

Delivered:

- Approved direction recorded in SPEC and decision/API design documents.
- Additive category schema-version migration; legacy version 1 retained byte-for-byte in upgrade probe.
- Seven bounded types, units, optional/null semantics, unknown-key/core-field collision protection.
- Shared model/import validation; JSON string form input corrected; typed activation admin-only.
- Optional-only additions for populated typed categories, legacy conversion rejection, category delete
  guard, category-row transaction locks and stale schema-model update conflict.
- Typed JSON export/import, with 9/23-column compatibility and draft-only duplicate-safe import behavior.
- Import exception logging restricted to class metadata.
- Linux case-sensitive Inertia page path fixed while retaining page-existence assertions.

## Changed files in this slice

Paths relative to repository root; existing unrelated dirty changes were preserved.

- `SPEC.md`, `.hermes/plans/2026-09-06_150803-agent-managed-catalog-crud.md`
- `docs/decisions/agent-write-contract.md`, `docs/proposals/agent-write-api.md`, this review
- `apps/management/database/migrations/2026_09_06_000002_add_category_schema_version.php`
- `apps/management/app/Services/Catalog/AttributeValidator.php`
- `apps/management/app/Models/{PackageCategory,ServicePackage}.php`
- `apps/management/app/Http/Requests/{PackageCategoryRequest,PackageRequest}.php`
- `apps/management/app/Http/Resources/PackageCategoryResource.php`
- `apps/management/app/Http/Controllers/Admin/ImportExportController.php`
- `apps/management/app/Services/PackageImportPreview.php`
- `apps/management/app/Imports/PackagesImport.php`, `apps/management/app/Exports/PackagesExport.php`
- `apps/management/config/inertia.php`
- `apps/management/tests/Feature/{TypedCatalogAttributesTest,TypedCatalogImportTest}.php`
- `apps/management/tests/Support/catalog-schema-upgrade.php`
- `apps/management/README.md`, `apps/management/docs/{ARCHITECTURE,IMPORT_FORMAT,TYPED_CATALOG}.md`

## Verification evidence

VM dev used an isolated copied workspace without `.env`/cached configuration. PHP test containers
overrode the entrypoint (no seeding/migration entrypoint), with no published ports. SQLite tests ran
with network disabled. MySQL 8.4 ran in a separate internal Docker network and temporary container,
capped at 384 MiB/0.5 CPU, with synthetic databases only. Production volumes/networks were not mounted.

| Check | Result |
|---|---|
| Local lightweight explicit-file PHPUnit, SQLite | PASS: 161 tests, 658 assertions |
| VM PHP 8.4 full PHPUnit, SQLite, after frontend/path corrections | PASS: 161 tests, 658 assertions (final recheck) |
| VM MySQL typed catalog + import + existing package/import tests | PASS: 51 tests, 152 assertions |
| VM MySQL full suite | FAIL: 2 of 161 tests, 656 assertions; Business Profile failures below |
| VM synthetic old-schema dry-run + additive migration + legacy byte comparison | PASS |
| VM `npm run typecheck` | PASS, exit 0 |
| VM `npm run lint` | PASS, exit 0 |
| VM `npm run build` | PASS, exit 0; existing large bundle warning (552.40 kB JS) |
| `git diff --check` | PASS |

The first VM run lacked assets (8 failed); after building, three remaining failures exposed default
`resources/js/Pages` vs repository `resources/js/pages` case mismatch. The config now points to actual
paths; no checks were disabled. MySQL initially exposed assertion-only JSON object key-order differences;
tests now ignore object key order while strictly asserting numeric/boolean/list value types.

Relevant commands from the isolated management directory:

```text
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --colors=never
php vendor/phpunit/phpunit/phpunit tests/Feature/TypedCatalogAttributesTest.php tests/Feature/TypedCatalogImportTest.php tests/Feature/PackageValidationTest.php tests/Feature/ImportTest.php --do-not-cache-result --colors=never
php tests/Support/catalog-schema-upgrade.php
npm run typecheck
npm run lint
npm run build
```

The upgrade probe refuses non-testing environments, non-MySQL, any other database name/host prefix,
or a database with migrations already present. It prepares old migrations, inserts synthetic fixtures,
checks `--pretend` did not apply anything, applies the additive migration and compares stored bytes.
Actual VM identifiers/directories are in the external deployment/dev record, not this repository.
After verification the temporary MySQL container, its disposable test data and internal network were
removed. Dev source/assets remain for review. Production container uptimes were unchanged.

## Review finding requiring direction

Two unchanged Business Profile tests fail only in the MySQL full run:

1. `Admin/BusinessProfileControllerTest::test_admin_can_update_the_profile`: expected singleton id 1,
   actual id 2.
2. `BusinessProfileApiTest::test_a_valid_token_can_read_the_profile`: API returns defaults instead of
   the inserted fixture's business name.

`BusinessProfile::current()` promises id 1 and calls `firstOrCreate(['id' => 1], ...)`, but `id` is not
fillable. MySQL auto-increment values also do not reset after transaction rollback, unlike the SQLite
test assumption. Both the singleton creation path and the API fixture's implicit ID need explicit review.
The model and both tests have no diff from HEAD and were not edited in this slice. This is a mismatch
with SPEC's singleton business profile contract, so AGENTS.md requires reporting rather than silently
choosing a different contract. No claim is made that production currently contains incorrect profiles.

Requested decision: may Codex fix the singleton persistence/test-fixture issue under a separate small
regression-tested slice before proceeding to catalog hierarchy/changesets?

## Limits and rollout

- The full original plan is **not complete**. No MCP CRUD tool or UI has been advertised as working.
- Record kinds/parent relationships, record optimistic versions, archive/restore/public visibility,
  evidence/revisions, API/MCP changesets, review UI and write-key opt-in remain unimplemented.
- FAQ/knowledge writes, OAuth/remote transport, PDF pipeline and live client/PDF pilots are not included.
- Category locks were exercised through sequential integration tests, not a parallel-writer stress test.
- Schema authoring UI is pending; the existing value JSON field can submit typed JSON, but there is no
  new UI or screenshot claim. No AI-to-customer runtime code changed; orchestrator tests were not rerun.
- Resolve MySQL full-suite findings before advancing rollout. No production DB backup/restore was
  performed because production migration was not authorized.
- Do not use `migrate:rollback` to discard the schema discriminator: down deliberately refuses.
  Retain typed data and schema; a string-only old editor is not a safe full write rollback after adoption.
- The next intended plan work is Phase 1B (shared versioned catalog writer and visibility), then
  changeset engine → MCP → admin review; each slice retains its own test/review gate.

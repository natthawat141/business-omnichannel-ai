# Catalog profiles delivery review

Date: 2026-09-11. Requirements: SPEC FR-PROFILE-001–004. Status: implemented and tested in isolated development; not deployed, pushed or seeded into production.

## Delivered

- Additive nullable profile discriminator and JSON data; no property-specific database columns or category rewrites.
- Versioned property_project/group and property_layout/variant schemas with strict types, ranges, facilities and evidence dates. Project/layout facts cannot carry offer price or availability.
- Admin form fields and checkboxes generated from the registry, unknown values, explicit unsaved-map clearing and parent/kind selection. The existing visual system was retained; JSON editing is not required for profiles.
- Agent schema discovery, allowlisted record projections, exact profile/kind/parent/facility search, source field paths and schema-version revalidation. Remote MCP plus updated CLI/stdio source support the filters.
- COCO PARC fixture: one project and six layouts, no actual offers or invented prices. Visible PDF facts were checked against rendered pages; a completion date present only in hidden extracted text was excluded.
- Review-detail bug fixed: PHP array union kept summary operations instead of full operations. `array_replace` now sends payload, sources and preview; an Inertia regression assertion checks the actual brochure proposal detail.
- Model-save regression found and fixed: omitted availability could otherwise inherit the database's available default after validation. Profiled records now persist unknown when availability is omitted/null.

## Changed surfaces in this slice

Paths are relative to the repository. Many files already contained unrelated uncommitted work; this list is not a claim that their entire diff belongs to this slice.

- `SPEC.md`, `apps/management/README.md`, `apps/management/docs/CATALOG_PROFILES.md`, this review.
- `apps/management/database/migrations/2026_09_11_000002_add_catalog_profiles.php`.
- `apps/management/app/Services/Catalog/CatalogProfiles.php`, `app/Services/Agent/AgentChangeService.php`, `app/Models/ServicePackage.php` (latter paths relative to Management).
- Management `app/Http/Requests/PackageRequest.php`, `app/Http/Resources/PackageResource.php`, `app/Http/Controllers/Admin/PackageController.php`, `app/Http/Controllers/Admin/AgentChangeController.php`, `app/Mcp/ManagementTool.php`.
- Management `resources/js/components/CatalogProfileFields.tsx`, `resources/js/pages/Packages/Form.tsx`, `resources/js/types/index.d.ts`.
- Management `tests/Feature/CatalogProfileTest.php`, `tests/Feature/RemoteMcpTest.php`, `tests/Fixtures/coco-parc-proposal.json`, `public/skills/management-proposals/SKILL.md`.
- `tools/document-intake-agent/src/client.ts`, `cli.ts`, `mcp.ts`, `types.ts`, and their three test files.

## Verified

All builds/tests ran on the authorized GCP VM in the isolated review tree `/home/macarthur/reviews/ai-setup-20260905`, not a live-release directory. Docker was not opened on the Mac. PHP tests used composer:2 (PHP 8.5) with development vendor dependencies and SQLite memory databases. Node checks used node:22-alpine. Test execution containers had network disabled and mounted only the review workspace.

| Check | Result |
|---|---|
| Initial new profile schema test | Red before implementation, then green |
| Profile/agent/lifecycle/validation/typed-catalog/Remote-MCP suites | 84 passed, 442 assertions |
| Full Management tests, excluding AppleDouble metadata files | 208 passed, 1015 assertions |
| Management `npm run typecheck` | Passed |
| Management `npm run lint` | Passed, zero lint warnings |
| Management `npm run build` | Passed; 700.99 kB JS, 209.78 kB gzip |
| Client `npm test` (TypeScript build + protocol/client/CLI tests) | 22 passed |
| `git diff --check` | Passed |

Full-suite command inside the isolated test container:

```sh
php artisan test $(find tests -type f -name '*Test.php' ! -name '._*') --compact
```

The first broad run found two missing/stale documentation fixtures in the review mirror and loaded AppleDouble sidecars. Synced existing public docs/llms.txt and selected real test files; no source assertions were weakened and no user files were deleted. The final full run above is green.

The COCO fixture passed preview → submit → admin review-detail → approve → apply in tests. All seven records were inactive unpublished drafts, linked correctly and absent from customer catalog results. A separate synthetic offer can attach directly to a project. Remote MCP OAuth/protocol tests executed exact facility search, not just schema listing. Profile version mismatch prevented all writes.

## Warnings and outstanding gates

- Frontend build warns about a chunk exceeding 500 kB. Dependency installation reported 7 audit findings (3 moderate, 4 high) for the existing Management lockfile; not remediated or assessed in this slice. The client install reported zero. No automatic dependency upgrades were performed.
- No production migration/deployment, real-key issuance, live COCO insert, PDF upload or public publication occurred.
- Production PHP 8.4/MySQL behavior, concurrent write contention, restore rehearsal and real connected-client authentication were not tested by the SQLite/PHP 8.5 run.
- Browser-level visual and interactive QA of the new form remains unverified; typecheck, lint, build and backend Inertia/request tests passed.
- Public customer project/facility retrieval is not added. The conversation orchestrator remains offer-only for catalog inventory and was not changed.
- Existing spreadsheet import/export does not round-trip the new profile fields. Use the profile-aware admin or proposal API; legacy import/export contracts remain unchanged.
- Existing downloadable/installed 0.2.0 client archives were not repacked or reinstalled. New local filter flags require building/reinstalling updated client source. Remote MCP changes require the normal Management deployment.
- Profile definitions are server-owned, not arbitrary runtime schemas. New industries require reviewed registry entries and version changes.

## Reviewer entry points

1. Read SPEC 6.9b and [profile contract](../../apps/management/docs/CATALOG_PROFILES.md).
2. Inspect the [COCO PARC JSON proposal](../../apps/management/tests/Fixtures/coco-parc-proposal.json) alongside source PDF pages 4 and 13.
3. Review profile validation, effective-record update behavior, and apply-time version checks.
4. Review admin profile switching/unknown fields, actual review payload/evidence and responsive browser behavior.
5. For a separately authorized rollout: back up first, deploy additive migration plus compatible writers, verify real MCP discovery/filter/preview and approved draft creation, then review publication separately. Retain profile columns on rollback; the down migration intentionally refuses to destroy their data.

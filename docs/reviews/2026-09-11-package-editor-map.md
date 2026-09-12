# Package editor, map links and responsive proposal review

## Scope

Product-owner request on 2026-09-11; SPEC FR-RE-001, FR-RE-004 and FR-RE-007.
Development only. No production deploy, database migration, push, CI provisioning or real
credential issuance in this change. Existing uncommitted profile/agent/user/document work
is preserved and must not be silently bundled into a deployment.

## Delivered changes

- `apps/management/resources/js/pages/Packages/Form.tsx`: image editing is the first section
  on create and edit, with contained preview, replace/remove actions and upload feedback.
  Save and image removal are disabled during upload. Existing file types and upload transport
  are unchanged. Removing the image clears the record reference only after saving; it does
  not delete the hosted image.
- The editor separates identity, pricing/terms, location/maps, area/rooms and publication.
  Profile/hierarchy/JSON controls are in a native disclosure, automatically opened for profile
  records or related validation errors. Error summary and sticky save/cancel controls remain
  accessible during long-form editing. Controls have explicit accessible names.
- `resources/js/pages/AgentChanges/Index.tsx`: badge and action text stay on one line; the
  table has an 880px minimum and scrolls inside a labeled, keyboard-focusable region.
  The hero action does not shrink. Existing bulk-apply and permission behavior is preserved.
- `2026_09_11_000003_add_map_url_to_packages.php`: additive nullable 2048-character field.
- `PackageRequest`, `ServicePackage`, `PackageResource`, `CatalogSearchController`,
  `AgentChangeService` and frontend types: persisted map URL, validated at request/proposal/model
  boundaries; included in catalog summary/detail and staff read/schema/preview/apply paths.
- `CatalogMapUrlTest` and `EditorPresentationTest`: validation, CRUD persistence, model and
  viewer boundaries, agent review/apply/publication boundary, resource delivery and synthetic
  editor HTML artifacts. Architecture and Management README updated.

The impeccable product-register guidance informed grouped sections, progressive disclosure,
existing monochrome components, explicit labels and responsive containment. No new UI package.

## Map contract and compatibility

`map_url` is optional, accepts HTTPS URLs up to 2048 characters, and is independent of a
property profile or category. The label recommends Google Maps; other map providers remain
allowed. This is syntactic validation, not verification that the destination is a map or is
trustworthy. No server fetch, redirect expansion, iframe, coordinate inference or geocoding.
The preview is an explicit external link with `noopener noreferrer`; non-HTTPS values do not
produce a clickable preview. Server validation remains authoritative.

Omitting `map_url` on update preserves it; explicit null clears it. Existing imports/exports
keep their column contracts and do **not** round-trip this field. Agents must discover current
server schema; there is no new apply/publish/root ability. Customer inventory eligibility is
unchanged. No Google Maps API key is needed because this is a link, not an embedded map.

## Verification

Tests and builds run only on the existing authorized development VM in the isolated review
directory `/home/macarthur/reviews/editor-20260911-PhAqoS`. No Docker or app build ran on the Mac.
Node formatting and a temporary loopback-only static preview server ran on the Mac.

- Node 22: `npm run typecheck`, `npm run lint`, `npm run build` passed.
- PHP tests: Composer PHP runtime with development vendor, network disabled, synthetic data,
  SQLite in-memory database, no production configuration/volumes.
- Final isolated editor regression suite: 209 passed, 1052 assertions. The expanded map
  suite separately passed: 6 tests, 52 assertions.
- Browser QA used the built React app with generated synthetic Inertia props, not production
  records. Desktop light and dark editor/proposal screenshots inspected. Form page overflow
  checked at 320, 390, 768 and 1024px; proposal page at 390, 768 and 1024px. No page-level
  horizontal overflow. The narrow table scrolls locally and badges compute `white-space: nowrap`.
- Typed a map link and verified the preview target; non-HTTPS input removed the link. Advanced
  disclosure opens and reveals the profile control. Viewport override reset after testing.
- `git diff --check` passed.

The initial test attempt raced the first frontend build and lacked a synthetic `.env`, causing
manifest errors/warnings. Prepared the isolated fixtures and rebuilt; the rerun passed without
weakening assertions. Build still warns about an existing JS bundle larger than 500kB
(approximately 700kB minified, 210kB gzip).

Unverified: actual Cloudflare image upload, full browser submit against a running backend,
MySQL production migration/concurrency, live customer channel rendering, and production rollout.
Browser fixtures are visual/interaction evidence only; persistence evidence comes from PHP tests.

Concurrent-work note: R2 upload-provider work modified SPEC and the editor upload handler while
this review ran. That work is preserved, not reverted. It owns FR-RE-006; this editor/map slice
uses FR-RE-007. The 209-test result belongs to the isolated editor snapshot (Cloudflare Images
transport), not a claim that the concurrently changing R2 stack has passed combined integration
or live-upload tests. Reconcile and verify the final combined tree before any release.

## Release and rollback gates

1. Review the exact diff against the live release. Local work includes prior approved but
   undeployed slices; copying the entire dirty workspace is not a scoped release.
2. Check existing category definitions for a `map_url` key collision before rollout: new core
   fields are reserved by typed-attribute validation. Do not convert existing data silently.
3. Back up the database and verify restore procedure. Record the currently deployed image
   digest and build a separately tagged candidate. Do not use mutable `latest` as rollback proof.
4. With explicit rollout authorization, apply the additive migration before activating code that
   writes the new field. Do not automatically run unrelated pending migrations or seeders.
5. Verify health, login, editor, map save/reload/clear and staff schema in the intended environment.
6. For application rollback, redeploy the recorded prior image digest and retain the additive
   column. Running Compose from an old source directory alone does not restore an overwritten
   image tag. The down migration drops map data and must not be used as routine rollback.

## Portable CI/CD recommendation (not implemented)

Recommended flow: GitHub Actions tests/build → GHCR immutable image → explicit release trigger
→ Docker Compose on the selected VM → health/smoke checks → recorded-digest rollback on failure.
Build on a hosted runner or dedicated build VM, not the user's Mac and preferably not the live
application VM. SSH deployment transport/config can change for GCP, Oracle OCI or another VPS
without changing application code. GCP IAP remains optional transport, not a product dependency.

- Keep runtime secrets and persistent database/upload volumes outside the image and Git.
- Pin third-party actions, verify SSH host keys, serialize deployments, restrict deploy authority,
  and fail closed if verification fails. Schema rollback is separate from app rollback.
- Confirm CPU architecture, especially for ARM OCI machines: build/test the matching architecture
  or a multi-architecture image. Verify persistent storage and backup/restore independently.
- Dokploy is an optional deployment dashboard supporting Compose, not a requirement. Prefer
  prebuilt images from CI. Audit existing ingress/ports and migration plan before installing a
  second proxy/control plane alongside the current Compose/Caddy configuration.
- Required-reviewer availability depends on GitHub plan/repository visibility. A manual release
  trigger is a baseline, not a claim that every account has protected-environment reviewers.

Primary sources consulted:

- [GitHub: publishing Docker images](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images)
- [Dokploy: Docker Compose](https://docs.dokploy.com/docs/core/docker-compose)

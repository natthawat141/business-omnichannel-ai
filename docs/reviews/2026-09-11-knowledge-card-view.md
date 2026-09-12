# Knowledge card view

Requirement: FR-KNOWLEDGE-UI-001, approved 2026-09-11. Development only; no deployment.

## Deployment update

Deployed after explicit product-owner approval on 2026-09-11. This supersedes the earlier
development-only status below. Only the knowledge index frontend and its controller were
applied to a copy of the previous live release; other pending development changes were
not bundled. Previous source and image retained for rollback. Migrations and seeding
explicitly disabled; only Management PHP was recreated and its nginx reloaded.

Production image build passed. Authenticated browser verified cards with existing records,
switch to table, and search retaining `view=table`. Public health and login both returned 200.
No records were edited. The profile-schema and document-retirement slices remain undeployed.

Changed `Knowledge/Index.tsx`, `KnowledgeEntryController.php`, SPEC and added
`KnowledgeViewTest.php`. Default responsive 1/2/3-column cards display plain-text body
previews, title, type, category, status, version and review date. A labeled Cards/Table
toggle retains the existing table and authorized edit/archive controls. No HTML is
rendered from knowledge content, no database or permission changes, no new dependencies.

The `view` query parameter selects the layout and remains in search/filter links and
server pagination. Unknown values fall back to cards. Type filter changes reset page.
The preference is URL-based, not stored in the user account or browser storage.

TDD: observed missing `filters.view` failure before implementation, then the view test
passed with 36 assertions. Build/tests run on the existing isolated GCP review workspace,
not on Mac Docker or production. Browser-level interactive/visual verification is not
claimed. Full Management suite passed: 199 tests / 974 assertions (excluding AppleDouble
metadata files). Typecheck, lint and build passed. Existing >500 kB bundle warning remains:
688.18 kB JS / 207.58 kB gzip. `git diff --check` passed. No deployment or migration performed.

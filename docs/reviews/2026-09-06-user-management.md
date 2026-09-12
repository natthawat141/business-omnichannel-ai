# User management review

Status: implemented in workspace; production migration/deployment not executed.
Requirements: SPEC FR-USER-001–005.

## Delivered

- Staff list/search/pagination and create/edit forms at `/admin/users`.
- Admin/editor/viewer roles, protected routes and business-content policies.
- Disable/reactivate rather than delete, final-enabled-admin protection, session invalidation.
- One-use private password links, hashed token/password storage, secret-free audit events.
- Ownership for newly web-issued keys; revocation and live owner checks.
- Navigation and content action visibility for restricted roles.
- Operator/migration guide: `apps/management/docs/USER_MANAGEMENT.md`.

## Main changed files

- `SPEC.md`; `apps/management/routes/web.php`; `apps/management/bootstrap/app.php`.
- Models `User`, `ApiToken`; new staff-access middleware; shared Inertia auth props; login request.
- New `Admin/UserController`, `Auth/ResetPasswordController`, additive staff-access migration.
- Existing API-token/AI-setup issuance controllers, five content policies and their form requests.
- React `Users/Index`, `Users/Form`, staff-aware AdminLayout and content list actions; auth types.
- Blade password-reset page; `tests/Feature/UserManagementTest.php`.

## Verification

- TDD: first run failed for absent routes/schema/permissions. Additional token-fragment and owner-role
  tests failed before their corresponding implementation changes.
- `php artisan test tests/Feature/UserManagementTest.php`: 14 passed, 74 assertions, exit 0.
- `rg --files tests -g '*Test.php' -g '!._*' -0 | xargs -0 php artisan test`:
  122 passed, 547 assertions, exit 0; SQLite in-memory only.
- Plain `php artisan test` discovers macOS AppleDouble `._…Test.php` metadata and reports warnings/exit 1
  despite passing behavioral tests. Explicit file selection excludes metadata without deleting disk files.
- VM `npm run typecheck`, `npm run lint`, `npm run build`: passed. Existing bundle-size warning remains
  (main JS approximately 552 kB, gzip 162 kB). No local Docker or local frontend build.
- Browser visual inspection of real built React components with synthetic static Inertia props:
  user list (light/dark) and account form (light) render. Fixture omits the production logo asset.
  This is visual verification, not a production account/credential workflow test.
- `git diff --check`: passed. Pre-existing dirty changes retained; no commit or push.

## Review/deployment gates

- Production MySQL migration and concurrency stress tests not run.
- Production login, private setup-link delivery and recipient password setup not run.
- Narrow/mobile layout and full interactive browser submissions not verified.
- Existing ownerless/service keys remain valid and are deliberately not assigned to a guessed user.
- Viewer has read-only list access, not editable detail forms. Private documents remain admin-only.
- Audit is account management only; AI catalog CRUD/revisions remain a separate scope.
- No user data was deleted and no real staff account, reset link or credential was created.

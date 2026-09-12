# Repository organization

Scope: FR-OPS-001–004. Repository naming, branch naming, documentation placement and agent automation.

## Changes

- GitHub repository renamed to `business-omnichannel-ai`; local checkout directory retained.
- `dev` renamed to `development`; `stg` renamed to `staging`. Commit history is preserved.
- CI, Release Steward, bootstrap PR and scheduled agent references use full branch names.
- Shared docs grouped under `architecture`, `operations`, `integrations`, `product`, and `design`.
- Historical conversational specification moved to `docs/archive/conversational-upgrade-spec.md`.
- AI setup design QA moved to the dated `docs/reviews/` collection.
- Original logo moved to `assets/branding/`; example installation manifests to `examples/management-agent/`.
- Added a documentation index and naming conventions.

Runtime directory paths, installed local packages, served assets, API paths, migration filenames,
production deployment and database state are unaffected. No user data was deleted.

## Verification and release limits

Review the commit's GitHub CI run for backend tests, frontend checks/build and CLI/AI tests.
Workflow YAML and documentation links are checked before handoff. Promotion uses the tested commit
for the staging ancestry check and accepts successful push CI runs on `development`.

Bootstrap PR #1 remains a separate operations-only PR. Its manual dispatcher has not been tested
end-to-end until the PR is merged into the default branch. Branch naming does not provision or
deploy a staging environment.

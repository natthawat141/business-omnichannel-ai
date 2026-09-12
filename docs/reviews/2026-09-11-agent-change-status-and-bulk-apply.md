# Review: Agent proposal status and bulk apply

Date: 2026-09-11
Requirements: SPEC FR-AGENT-004, FR-AGENT-005, FR-AGENT-006

## Delivered

- Fixed the proposal-detail status message so an `applied` change set says it was saved and its new records remain drafts. It no longer instructs an operator to apply it again.
- Added a deliberate administrator-only bulk action on `/admin/agent-changes`. An operator can select up to 25 already-approved change sets on the current page and confirm one apply action.
- The bulk action locks and revalidates every selected set in one database transaction. A version/schema/source conflict rolls back every business-data write from that action. The conflicting change set is marked `conflicted`; the other selected sets remain `approved` for review or a later retry.
- No bearer token, MCP client, or AI capability was added for approval, bulk apply, or publishing. New Catalog records stay unpublished and FAQ/Knowledge records stay inactive.
- Updated the Management architecture and staff-agent API contract to describe the bounded, admin-confirmed bulk behavior.

## Verification

Run on the existing GCP development VM, using an isolated in-memory SQLite test environment:

- `php artisan test tests/Feature/AgentChangesetTest.php` passed: 14 tests, 69 assertions.
  - Includes new success coverage for applying two approved sets while keeping every create unpublished.
  - Includes new conflict coverage proving that a later conflict rolls back an earlier selected set's business writes.
- `npm run typecheck` passed.
- `npm run lint` passed.
- `npm run build` passed.

## Review notes and remaining checks

- The build retains the existing Vite warning about a JavaScript chunk above 500 kB. It does not block this scoped change.
- The local workspace's PHP test command cannot bootstrap because its `vendor/` directory is incomplete; verification was therefore performed on the VM test workspace with the locked dependencies installed. No Docker was run on the local Mac.
- This change has not been deployed. A live browser check of the new selection and confirmation flow must be performed after a separately authorised deployment.
- Bulk apply is intentionally not bulk approval. Each proposal still needs a human review and individual approval before it becomes selectable.

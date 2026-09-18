# Reliability repair review — 2026-09-12

Scope: FR-REL-001–004. Implementation baseline `9093b55`; integration branch `development`.
No production deployment, migrations, real credential issuance, or local Docker execution.

## Delivered

- `services/ai/src/ai_service/main.py`: pinned Chatwoot attribute/status contract, verified and
  resumable team routing, fresh ownership checks, no ordinary explicit AI-mode reset, grouped
  price parser, Latin word boundaries, redacted LINE logging, shared public delivery claims.
- `services/ai/src/ai_service/reliable_queue.py` and `worker.py`: existing Redis FIFO processing
  list, crash recovery, explicit ACK, bounded retries, seven-day historical dedup, renewable
  singleton lease with fenced writes, review queue for ambiguous sends.
- `apps/management/database/seeders/RealEstateDemoSeeder.php`: create missing demo records only,
  preserve edited business data, refuse demo seeding in production.
- `compose.yml` / bootstrap: startup/demo seeding off by default; allowlisted AI environment.
- Regression tests and CI: isolated real Redis, deployment configuration validation, and
  assertions that AI containers do not inherit unrelated database/admin secrets.

## Evidence

- RED [run 34671652353](https://github.com/natthawat141/business-omnichannel-ai/actions/runs/34671652353):
  reproduced 9 AI failures and the Business Profile overwrite before implementation.
- Additional red tests reproduced missing queue recovery, incomplete handoff, resolved-thread
  reopening, and cross-channel replay after changed catalog results.
- GREEN [run 34672429384](https://github.com/natthawat141/business-omnichannel-ai/actions/runs/34672429384)
  on `9093b55`:
  - AI syntax check and **71 tests passed** (including real Redis tests; two dependency deprecation warnings).
  - Management **212 tests / 1,058 assertions passed**, frontend typecheck/lint/build passed.
  - Document intake agent **22 tests passed**.
  - Tracked-file policy and Compose/environment-isolation validation passed.
- `git diff --check` passed. No database schema changes.

Tests simulate the pinned Chatwoot replacement contract; they do not prove live Chatwoot/LINE
behavior. Updated mocks now replace attributes rather than silently providing a nonexistent merge.

## Remaining approval and rollout gates

Read [reliability and rollout](../operations/reliability.md) before promoting to production.
Chatwoot's non-atomic GET/POST contract still leaves a narrow ownership/attribute race; strict
atomicity needs an approved server-side conditional-write/send contract. No claim of 100%
production readiness is made. External live smoke tests and Redis persistence/monitoring remain
unverified. A seven-day dedup window is not indefinite exactly-once delivery.

Only green development → staging promotion and a main PR are authorized here. Main merge and
deployment need separate approval. Stop old workers before rollout; rollback must reconcile
processing and ambiguous deliveries instead of simply restarting old BRPOP code.

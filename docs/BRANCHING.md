# Branch and release workflow

This repository uses three long-lived branches:

| Branch | Purpose | Deployment rule |
| --- | --- | --- |
| `dev` | Integration branch for reviewed development slices | Development checks only; never treated as production |
| `stg` | Release candidate promoted from `dev` | Deploy only to a staging environment and run smoke tests |
| `main` | Production source of truth | Promote from `stg` only after approval, backup and rollback checks |

## Normal flow

1. Start a short-lived feature branch from `dev`, or commit a recovery checkpoint to `dev`
   when preserving an existing mixed working tree.
2. Run the relevant backend tests, frontend typecheck, lint and build. Record what was not
   verified. Scan the staged diff for credentials and local artifacts before pushing.
3. Review and merge into `dev`.
4. Ask the Release Steward to promote the exact green commit from `dev` to `stg` and prepare the
   `stg` to `main` pull request. Deploy the same immutable image digest
   to staging and verify migrations, health, login, critical UI/API flows and rollback commands.
5. Promote the exact staging commit from `stg` to `main` after product-owner approval. Production
   deploy uses the same tested image digest; it does not rebuild a different artifact.

## Rules

- Do not force-push `stg` or `main`.
- Do not push directly to `main` for normal work. Emergency fixes start from `main`, pass tests,
  then merge back through `stg` and `dev` so branches do not drift.
- Never commit `.env`, credentials, tokens, private keys, database dumps, runtime data,
  dependency folders or local tool state.
- A successful build is not a deployment. Each environment records deployed commit, image digest,
  migrations, health checks and rollback target.
- Database backup/restore and application rollback are separate. Prefer retaining additive columns
  when rolling application code back.
- The current VM is a development/deployment target, not part of the branch contract. CI may build
  an OCI-compatible image in a hosted runner and deploy it to GCP, Oracle Cloud or another VPS.
- `dev` to `stg` is automation-managed and fast-forward only. `stg` to `main` is always a pull
  request and requires the product owner's production approval.

See [Release Steward](RELEASE_STEWARD.md) for the agent prompt, CI contract, promotion command, and
handoff format.

## Initial branch state (2026-09-12)

`main` and `stg` begin at the last published production baseline. The accumulated local work is
captured on `dev` first so it is backed up and reviewable without silently promoting unfinished
or previously undeployed slices to staging or production.

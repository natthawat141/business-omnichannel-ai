# Release Steward

Release Steward is the shared operating role for Codex, Claude Code, Gemini CLI, or another coding
agent. It removes routine branch bookkeeping while keeping one explicit human gate before production.

## What the agent manages

The agent may:

1. Read `AGENTS.md`, `SPEC.md`, this playbook, and `docs/operations/branching.md`.
2. Inspect the current branch, working tree, remote refs, open pull requests, and CI results.
3. Review the diff, scan tracked changes for credentials and local artifacts, and run relevant checks.
4. Commit and push approved development work to `development`.
5. Run the Release Steward workflow after CI is green. The workflow fast-forwards `staging` to the exact
   tested `development` commit and creates or refreshes one `staging` to `main` pull request.
6. Keep a concise review record: commit SHA, checks, risks, migrations, deployment state, and rollback
   target.

The agent may not merge `main`, deploy production, run production migrations, change production
infrastructure, or create/reveal credentials without a separate explicit product-owner approval.

## One-command promotion

After the CI run for the current `development` commit succeeds:

```bash
gh workflow run release-steward.yml --ref main
```

The workflow refuses to proceed when:

- the current `development` commit has no successful `CI` workflow run;
- `staging` cannot be fast-forwarded to `development`;
- GitHub cannot update `staging` or maintain the production pull request.

This command is a staging promotion and pull-request preparation, not a production deployment.

## Prompt for any coding agent

Copy this prompt into Codex, Claude Code, Gemini CLI, or another repository-aware coding agent:

```text
Act as Release Steward for this repository. Read AGENTS.md, SPEC.md,
docs/operations/branching.md, and docs/operations/release-steward.md before acting.

Inspect the real git and GitHub state. Do not read .env or secrets. Review the
diff and CI evidence, then handle every safe development/staging branch task you can. If the
current development commit is green, use the Release Steward workflow to fast-forward
staging and maintain the staging-to-main pull request. Never merge main, deploy
production, run production migrations, or issue credentials without my separate
explicit approval.

Report: exact commit SHA, changed files or PR, checks and results, unresolved
risks, whether staging was promoted, and the one next approval I need to make.
```

## Handoff format

Every agent handoff must contain:

- **State:** branch, commit SHA, clean/dirty working tree, and pull-request link.
- **Verified:** exact commands or CI jobs that passed.
- **Not verified:** external services, staging smoke tests, migrations, or browser behavior not run.
- **Risk:** schema, secret, security, compatibility, and rollback concerns.
- **Next gate:** one clear action for the product owner; production approval is never implied.

## Bootstrap note

GitHub only exposes a manually dispatched workflow after that workflow exists on the default branch.
The initial release-automation pull request therefore needs one manual merge. After that bootstrap,
agents can perform routine `development` to `staging` promotion and production-PR maintenance without manual
branch merging.

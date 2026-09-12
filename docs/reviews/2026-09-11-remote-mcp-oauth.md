# Remote MCP + OAuth — implementation review

## Outcome

Implemented approved SPEC FR-SETUP-008–010 on the development VM. Default setup is
now a Thai, three-step browser connection page; existing local setup is under
Advanced. English Copy prompt, public HTML/Markdown documentation and the existing
skills link to the appropriate transport. The previous documentation design stays.

The server provides OAuth authorization code with required PKCE S256, explicit
consent using existing login, 30-minute access tokens, fixed seven-day connections,
refresh, scope-limited MCP and user-controlled revocation. There is no direct AI
apply/publish or password grant. Proposal handling reuses the existing service.

## Files / contracts

- `apps/management/routes/ai.php`, `config/remote-mcp.php`: gated endpoints and settings.
- `app/Http/Middleware/{McpOAuthBoundary,RemoteMcpAccess}.php`: authorization boundaries.
- `app/Http/Controllers/Auth/McpOAuthController.php`: discovery and allowlisted registration.
- `app/Services/Agent/McpConnections.php`, `app/Models/McpConnection.php`: grant lifecycle.
- `app/Mcp/{ManagementServer,ManagementTool}.php`: scoped, bounded protocol adapter.
- Five published Passport migrations plus one additive connection migration.
- User/Passport integration, login full-page OAuth return and AI Setup connection APIs.
- `resources/js/pages/AiConnect.tsx`: simple setup and in-page revoke confirmation;
  old `AiSetup.tsx` retained as Advanced. Tailwind dark variant now follows the
  existing `data-theme` switch instead of the operating system preference.
- `resources/views/mcp/authorize.blade.php`: consent, CSRF, scope explanation.
- `resources/views/docs/remote-mcp.blade.php`, public Markdown/skills, llms index,
  [operator deployment notes](../../apps/management/docs/REMOTE_MCP.md).
- Composer lock adds Laravel MCP 0.9.5 / Passport 13.8.0 and dependencies.
  Targeted security updates also fix existing Guzzle/CommonMark/Excel advisories.

## Verified on GCP development environment

- Separate workspace: `/home/macarthur/builds/oauth-20260911/management` on
  `dev-container-1`, project `aione-zone1`, zone `asia-southeast1-b`.
- PHP 8.4.25: full `vendor/bin/phpunit --no-progress` passed **193 tests / 891 assertions**.
  Real code/PKCE exchange and signed bearer verification are exercised by
  `RemoteMcpTest`, not an authentication mock. Includes denial, CSRF, expiry,
  replay/wrong verifier, scoped proposals, refresh identity and disconnect.
- `npm run typecheck`, `npm run lint`, `npm run build`: passed. Build retains a
  >500 kB JavaScript chunk warning; no build failure.
- `composer audit`: no vulnerability advisories found after targeted updates.
- Separate actual HTTP smoke passed: unauthorized challenge → registration →
  cookie session and CSRF consent → PKCE exchange → MCP initialize → two metadata
  tools → real empty document-list result. Only synthetic records/credentials used.
- Desktop visual checks: AI Setup and linked public documentation render; Copy page
  reports success. Corrected OS/app-theme mismatch found during screenshot review.
- Final in-page disconnect confirmation was exercised in the browser against the
  synthetic HTTP-smoke connection; UI changed to revoked and reported success.
  Native browser confirmation was replaced after it stalled the first test tab.
- `git diff --check`: passed. At the time of this development review, no Git push,
  commit, production migration or production credential issuance had been performed.
  All dependency installation, tests and builds were
  remote; Docker was not started on the Mac.

## Preview and limits

Dev preview is forwarded to `http://127.0.0.1:18766`; `/preview` signs into an
isolated synthetic fixture and `/docs/remote-mcp` is public. It is loopback-only on
the VM via SSH, not an endpoint cloud agents can reach. The disposable preview
router lives outside the repository, never in production routes. Test signing keys
are generated on the VM and are not committed. Do not copy preview environment
configuration or its synthetic application encryption key into production.

Remaining release gates: actual supported AI application/account OAuth test,
public HTTPS/network/callback behavior, production signing-key persistence and
reverse-proxy log hygiene, backup/restore verification, and MySQL parallel refresh /
revoke / user-access races. SQLite tests and synthetic HTTP smoke do not prove these.
Remote MCP stays disabled by default and requires explicit environment enablement.
Dedicated mobile/device testing remains unperformed. No guarantee is made that a
prompt installs a connector.

## Production deployment (approved 2026-09-11)

- Target: GCP project `aione-zone1`, VM `ai-bot-chatwoot-vm`, zone
  `asia-southeast1-b`; release source is
  `/home/macarthur/releases/2026-09-11-remote-mcp-oauth`.
- A source archive and verified MySQL logical dump were made before migration in
  `/home/macarthur/deploy-backups/20260911T090000Z-remote-mcp-oauth` with root-only
  file permissions. The existing named volumes and Chatwoot/AI services were retained.
- Only Management PHP/Nginx and Caddy were rebuilt/recreated. The production override
  explicitly sets `REMOTE_MCP_ENABLED=true`, canonical `APP_URL` to
  `https://mgmt.aionecloud.space`, secure cookies and disables automatic migration/seeding
  during ordinary restarts.
- The six additive Passport/MCP migrations are `Ran`; persistent Passport signing keys
  exist in the Management storage volume. No customer catalog, conversation, or proposal
  data was modified by the deployment.
- External checks from outside the VM passed: Management docs `200`, OAuth authorization
  metadata `200`, protected-resource metadata `200`, and unauthenticated MCP JSON-RPC
  returns the expected `401` Bearer challenge with `Cache-Control: no-store`.
- Release fixes found during deployment: clean Docker builds now create the ignored
  `storage/` directory; Nginx explicitly passes `/.well-known/` discovery routes instead
  of treating them as hidden files; Caddy preserves `mgmt.aionecloud.space` alongside the
  existing sslip hostname.

Still unverified after deployment: a real supported ChatGPT/Claude/Codex client account
completing browser consent and successfully invoking MCP, MySQL concurrent refresh/revoke
races, backup restore rehearsal, and dedicated mobile/device tests. Chatwoot and the AI
runtime retain their existing sslip hostnames because public DNS names for those services
have not been configured.

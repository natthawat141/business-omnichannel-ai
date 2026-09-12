# Browser-authorized Remote MCP

Implements SPEC FR-SETUP-008–010. Existing local stdio/CLI package and bearer APIs
remain compatible and separate. This is a **development implementation**, not a
claim of verified ChatGPT Work / Claude Cowork / Antigravity account compatibility.

## Architecture

Laravel MCP 0.9.5 provides Streamable HTTP JSON-RPC. Passport 13.8 / League OAuth2
provide authorization-code exchange, PKCE, signing, revocation and refresh. We use
their controllers rather than implementing token cryptography. Only authorization
code and refresh grants are routed; password, implicit, device, PAT and client
credentials routes are not exposed.

- `routes/ai.php`: `/mcp`, discovery, bounded registration and OAuth endpoints.
- `McpOAuthBoundary`: feature gate, S256 requirement, resource binding, explicit
  consent, current administrator/session checks, response privacy, transaction bounds.
- Existing Inertia login performs a full-page return to the HTML consent screen.
- `RemoteMcpAccess`: bearer-only Passport verification, active admin, current
  security version, token/connection expiry, exact granted scopes and revocation.
- `McpConnections`: stable internal proposal principal across refresh. These
  principals have no issued plaintext and cannot authenticate to legacy APIs.
  Actual OAuth access tokens are not forwarded to another service.
- `ManagementTool`: scope-filtered tools, bounded inputs, existing controller/service
  validation and safe responses. No approve/apply/publish, SQL or PDF content tools.

OAuth token identifiers and scope metadata are persisted; no plaintext access or
refresh credentials are stored in app tables. Private signing keys live outside
Git. Connection UI contains only names, scope descriptions, dates and numeric
connection IDs, not credentials or callback authorization codes.

## Consent and lifetime

`mcp:use` defaults to document metadata. Extra `agent:read`, selected
`agent:catalog` / `agent:faq` / `agent:knowledge`, and `changes:write` are explicit
consent scopes. Setup's read/proposal selector changes the copy prompt, not the
server grant. Scope selection capabilities vary by client; always inspect consent.

Access tokens expire in 30 minutes. Refresh tokens and connection lifetime are
bounded to 7 days; refreshing does not extend the original connection. Refresh
scope changes are rejected rather than silently widening access. A fresh browser
authorization is needed after expiry/revocation or to change permissions.

Disconnect revokes access/refresh tokens, invalidates pending auth codes for that
user/client, and suspends pending proposals. Account security changes revoke
connections. Application-level locks serialize issuance, disconnect and MCP calls
using the stable first user row (single-business scope). Concurrent behavior still
needs a MySQL parallel-client test before production; SQLite is not concurrency proof.

## Deployment prerequisites (not executed on production by this slice)

1. Install locked PHP dependencies, build frontend and back up the database.
2. Run additive migrations including the published Passport tables and
   `2026_09_11_000001_create_mcp_connections.php`. No existing business tables are dropped.
3. Provision persistent Passport signing keys securely using `php artisan passport:keys`
   only when absent. Do not force replacement of existing keys. Preserve keys between
   releases, mode 0600 and readable by the PHP runtime. Keep APP_KEY stable too.
4. Set APP_URL to the canonical public HTTPS origin; set APP_DEBUG=false and secure
   session cookies. Route discovery, `/oauth/*` and `/mcp` through the existing proxy.
   Avoid buffering long MCP responses; current tools return non-streaming JSON.
5. Configure `REMOTE_MCP_REDIRECT_ORIGINS` after verifying exact callback origins from
   each client. Defaults permit loopback HTTP and known cloud origins, NOT every
   possible client callback. No wildcard or custom URL schemes are accepted.
6. Strip query strings from access logs for `/oauth/authorize`; never log request
   bodies, Authorization headers, tokens, cookies or redirects containing auth codes.
   Inspect reverse-proxy logging before enabling. Application code does not log tool
   inputs/results. Restrict public registration at ingress if abuse is observed.
7. Set REMOTE_MCP_ENABLED=true, refresh configuration and test from the actual AI
   client/network. A reachable website is not proof of MCP OAuth compatibility.

## Verification / rollback

`tests/Feature/RemoteMcpTest.php` drives real code exchange and signed bearer
authentication with synthetic users and temporary keys, not Passport::actingAs.
It covers discovery, feature gate, callback host attacks, login → consent, denial,
CSRF, wrong verifier/replay, scoped tools/proposals, fixed expiry, refresh identity,
revoke, account disable/re-enable, and public/simple/Advanced pages.

Disable REMOTE_MCP_ENABLED to stop new remote calls/authorization/refresh, leaving
local keys and business records intact. Keep added tables for audit and rollback;
do not roll back migrations with live grant/proposal history. Disabling is not
revocation: re-enabling can reactivate otherwise valid tokens. Explicitly revoke
connections when permanent disconnection is intended.

Primary references: [Laravel MCP](https://laravel.com/docs/13.x/mcp),
[Laravel Passport](https://laravel.com/docs/13.x/passport).

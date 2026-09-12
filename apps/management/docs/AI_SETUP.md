# AI connection setup

## Connected documentation refresh (2026-09-11)

The Setup header links directly to `/docs/agent-setup`; the public documentation links back to
Setup. Documentation Copy Page copies the public Markdown reference. The admin Copy Page
copies the selected client's credential-free instructions, never the issued token.

The English installation prompt covers preflight, existing configuration, installation, private
operator credential entry, registration, actual tool verification, document/proposal handling,
and error diagnosis. The prompt panel offers Preview and Markdown views using existing Base UI
tabs. Human-facing explanations remain Thai. No runtime permissions or transport changed.

Verification: GCP development build/typecheck/lint passed; setup and documentation PHPUnit
tests passed (13 tests, 106 assertions). Browser verification covers the public Docs page and
Copy Page, plus the VM-built Setup page using synthetic props: Preview/Markdown switching,
English prompt rendering and copy feedback. This change has not been deployed to production.

## Current v0.2 proposal setup (2026-09-06)

In addition to the unchanged metadata-only `documents:read` key, an administrator can now explicitly choose **Proposal for admin review** and select Catalog, FAQ, and/or Knowledge. The server issues only `agent:read`, `changes:write`, and `agent:<entity>` abilities. It never accepts a browser-supplied arbitrary ability list and the proposal key has no approve, apply, publish, SQL, upload, PDF-download, or schema-write capability.

The only write-shaped path is `schema → bounded record read → preview → submit → admin review → separate admin apply`. Submission stores an immutable change set, while every business record remains unchanged until the CSRF-protected administrator action. New Catalog records are unpublished with `availability=unknown`; new FAQ/Knowledge records are inactive. Security revocation suspends pending proposals, while an ordinary expiry blocks new API requests without deleting audit history.

The public `/skills/management-proposals/SKILL.md`, `/docs/agent-setup`, and `/docs/agent-setup.md` describe this v0.2 contract. The versioned package artifact is `document-intake-agent-0.2.0.tgz`; it preserves `document_list`/`document_get` and adds proposal-only commands/tools. Work/Cowork remain unavailable because this implementation is still local stdio only.

## Readable documentation and Antigravity (2026-09-06)

FR-SETUP-007 adds public server-rendered HTML at `/docs/agent-setup`, with heading navigation, light/dark styling, copy controls and direct Markdown/skill links. It deliberately uses a plain Blade response without Inertia's authenticated account props. No account records or keys are queried for documentation. Existing `.md` URLs remain compatible.

Antigravity is an additional setup-key client with metadata-only or explicit proposal scope, TTL options, admin/CSRF/throttling and revocation. `AgentSetupExamples::antigravity()` is the shared placeholder-only JSON template for admin UI and public docs. The user enters the secret into a private global config outside Git, not an agent prompt. The template requires actual absolute node/script paths and a manual Refresh MCP action. Never infer that the IDE inherits a terminal environment or that a template is an established connection.

The Antigravity instructions were checked against https://antigravity.google/docs/mcp on 2026-09-06. Actual customer-account connection remains unverified. Cloud MCP/OAuth is not introduced.

## Agent-first onboarding (2026-09-06)

FR-SETUP-006 follows the product owner's Agent setup reference: horizontal client selection, Agent setup (default) and Manual setup, a visible per-client prompt with Copy prompt, then separate key issuance and verification instructions. Impeccable informed the task sequence and existing light/dark component styling.

The prompt includes the configured Management origin, `/docs/agent-setup.md`, tool SKILL.md, public package installation, operator-only hidden Bash key entry, client-specific registration and a real metadata-read check. Keys remain outside generated prompts and commands. No success state is fabricated. Existing expiry/revocation/scope behavior is unchanged.

Release prerequisite: build/test `tools/document-intake-agent` on the VM, run npm pack using the files allowlist, and stage the archive as `public/downloads/document-intake-agent-0.2.0.tgz` before building the Management image. It contains compiled tools and runtime dependency declarations, not node_modules or private data. A consumer still needs Node.js 22+, npm registry access and the selected local client. Test archive contents and a clean installation before deployment. No npm registry publication is needed.

Preserve the current VM's compose.override.yml and Caddyfile when creating a release so the approved custom domain, HTTPS and cookie settings are retained. Exact environment identifiers and rollback details remain outside source control.

Work/Cowork still show an explicit unavailable state, not a copyable pretend connector. Installation docs do not complete remote MCP/OAuth or third-party login. Key entry is operator-driven in a private terminal, not captured through an agent tool.

## Public AI skill (2026-09-06)

FR-SETUP-005 publishes `/skills/document-intake/SKILL.md` and `/llms.txt` as static, unauthenticated documentation, without business records or credentials. Setup offers open/download links and a copyable Thai prompt containing only the skill URL. Downloading a Markdown file is not automatic skill installation, plugin registration or authentication.

The skill-creator format informed the frontmatter, task triggers and bounded read workflow. The skill describes the actual document_list/document_get contracts, local CLI fallback, token expiry/errors and metadata-only limits. It explicitly does not suppress credential-exposure warnings or invent remote endpoints.

Recommended next slice, not implemented here: authenticated remote MCP plus OAuth account consent, with short-lived access tokens handled outside chat, explicit grant lifetime and revocation. This separates token lifetime from how often a human must reconnect. Compatibility must be tested in actual client accounts. See [OpenAI MCP](https://learn.chatgpt.com/docs/extend/mcp) and [Cowork architecture](https://support.claude.com/en/articles/14479288-claude-cowork-architecture-overview). No OAuth provider, persistent refresh policy or new authorization scope is selected by this documentation slice.

Review checklist: skill frontmatter validation; public skill/index serving; copied prompt contains no key; frontend typecheck/lint/build on VM; existing key-scope/expiry/revocation tests. `AiSkillDocumentationTest` checks documentation contract and prompt construction; it is not an end-to-end connector test. No real key issuance or cloud connection is part of this change.

## UI refresh (2026-09-06)

- Integration workspace with client selector and setup panel; key management is a separate tab with active/history filtering.
- Setup commands are progressively disclosed. Copy actions, expiry feedback and retained in-memory keys support switching between views without issuing unnecessary replacement keys.
- Neutral existing design system, scoped light/dark styles, keyboard focus, reduced-motion handling and a stacked mobile layout.
- UI files: `resources/js/pages/AiSetup.tsx`, `resources/css/ai-setup.css`; v0.2 adds an explicit proposal scope selector and entity checkboxes. It does not increase a metadata key's permissions.
- VM typecheck/lint/build passed. Browser inspection used the actual compiled app with synthetic props: desktop 1280px, mobile 390px iframe, dark mode, key-management tab, instruction disclosure and unavailable cloud-client state. Production browser was logged out; authenticated production issuance/revocation was not exercised for this visual refresh.
- The requested UX UI Pro Max skill was not installed. Impeccable's product guidance informed information hierarchy, progressive disclosure and state styling.
- Final production browser session became available: visually verified the deployed connection panel and empty key-management view. Key issuance/revocation was not performed.

Implementation: FR-SETUP-001–004, NFR-SEC-003. Route: `/admin/ai-setup`.

## Browser connection (2026-09-11)

The default `/admin/ai-setup` is now the simple browser-authorization screen.
Local setup below remains at `/admin/ai-setup?advanced=1`.
See [REMOTE_MCP.md](REMOTE_MCP.md) for feature gating, scopes, migrations, keys,
security, tests and client-compatibility limits. Remote MCP is disabled by default
until explicitly configured; copying a prompt is not a successful connection.

## Advanced local user flow

An authenticated administrator selects CLI, Codex CLI or Claude Code, then creates a key for 15, 60 (default) or 240 minutes. The plaintext appears only in the creation response and in component memory. Copy the key into the hidden Bash prompt; generated commands contain no key. The page lists the latest 50 setup keys for the single business, including expiry and last usage. Revocation immediately blocks subsequent API calls. Issuing another key does not revoke existing keys.

Metadata keys expose only `document_list` and `document_get`: safe document metadata, not PDF content or OCR. Proposal keys additionally expose schema/record/preview/submit/status tools but cannot apply, publish, or change schema. The client and the built package must be installed on the same machine. Running the package on a VM does not automatically connect desktop applications elsewhere. The page explicitly states that first-time setup still needs an administrator.

## API and security

- `POST /admin/ai-setup/keys`: JSON `{client, minutes, scope?, proposal_entities?}`. Supported clients: `cli`, `codex`, `claude-code`, `antigravity`. Admin session, CSRF and 5 requests/minute apply. Returns `{id, key, expires_at}` once with `Cache-Control: no-store`.
- `DELETE /admin/ai-setup/keys/{token}`: admin-only revocation of non-protected setup keys; existing unrelated/system tokens are excluded.
- Scope is server-chosen: omitted/default is `documents:read`; `scope=catalog_proposals` requires a bounded selected entity list and yields only proposal abilities. Posted arbitrary `abilities` are ignored. Existing API authentication enforces expiry/revocation on every request. Proposal/revision history requires the additive agent-change migration.
- Plaintext never enters Inertia props/history, session flash, URLs, logs or local/session storage. No production key is issued by tests.
- Set `APP_URL` to the externally reachable HTTPS Management origin. The generated Bash command shell-quotes this configured URL. Deploy behind HTTPS with existing session/CSRF middleware enabled.
- JSON-expecting web requests now receive JSON authentication/validation errors; ordinary browser redirects are preserved.

## Compatibility and remaining work

Codex instructions explicitly forward environment variables using its `env_vars` configuration. Claude Code uses the stdio transport. Instructions were checked against [OpenAI documentation](https://learn.chatgpt.com/docs/extend/mcp?surface=cli) and [Claude Code documentation](https://code.claude.com/docs/en/mcp).

ChatGPT Work and Claude Cowork remain unsupported by the **local stdio** method.
The new remote method is separate and feature-gated; it must be verified with each
real client/account before claiming that integration is available.

## Review evidence (2026-09-05)

- Targeted backend suite: setup, existing token management, token model, and Document API: 26 tests / 139 assertions passed with exit code 0 using explicit PHPUnit file arguments.
- Frontend `npm run typecheck`, `npm run lint`, `npm run build`: passed on the configured GCP VM in a separate review directory.
- Existing dependency audit reports 6 issues (2 moderate, 4 high); no dependency upgrade was made. Build reports a bundle-size warning.
- Deployed to the existing approved GCP environment: Management PHP only. The setup routes are registered, compiled assets contain the page, health/login endpoints respond, and an unauthenticated setup request redirects to login (302). The prior image remains tagged for rollback; deployment paths are recorded outside the repository on the VM.
- External AI-client login and authenticated browser interaction verification remain unperformed. No real key was issued during delivery. Cloud clients remain unavailable as described above.
- Local `php artisan test --filter=...` executed all 26 tests successfully but exited 1 while discovering macOS AppleDouble test sidecars. Explicit PHPUnit file arguments avoid those sidecars without deleting user files.

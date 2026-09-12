# Business Omnichannel AI — Version 1 Specification

## Document Status

- Version: 1.0
- Status: Product direction approved. The Version 1 stack and lean real-estate slice in FR-RE-001–005
  are deployed to the configured GCP VM. Management/AI health, the additive property-image migration,
  the admin direct-upload route/assets, and the internal AI-to-Catalog API path are verified. Cloudflare
  Images runtime credentials and a live image-upload/channel end-to-end test remain pending. Production
  configuration is not source controlled.
- Date: 2026-08-17
- Last operational update: 2026-09-12
- Product owner: User
- Tech lead: Codex
- Implementation: Codex, only after product-owner approval; `agy` is not used

## 1. Product Goal

Build a single-business AI assistant that serves customers through LINE and WhatsApp conversations unified in Chatwoot. Business staff manage catalog data and knowledge in Management. The AI answers from current structured records, can search inventory on demand, and hands the conversation to a Chatwoot team whenever human judgement is required.

The system must support general businesses rather than a language-school domain. Real estate is a required reference scenario, not a hard-coded vertical.

Example customer questions that Version 1 must support:

- “มีที่ดินแถวบางนาไหม”
- “มีคอนโดโครงการไหนบ้าง”
- “คอนโด 2 ห้องนอน งบไม่เกิน 4 ล้านบาทมีอะไรบ้าง”
- “แพ็กเกจที่ถูกที่สุดราคาเท่าไร”
- “โปรโมชันนี้ยังใช้ได้อยู่ไหม”
- “ขอคุยกับเจ้าหน้าที่”

## 2. Confirmed Product Decisions

1. Version 1 supports one business only. It is not a multi-tenant SaaS platform.
2. Human handoff routes to a Chatwoot team, not a specific individual.
3. Version 1 does not implement booking, appointments, calendar APIs, payment collection, or payment execution.
4. Management/MySQL owns business data. Chatwoot owns conversations.
5. Chatwoot is the only primary channel/conversation path for LINE and WhatsApp.
6. The AI queries business information through authenticated Management APIs and never connects directly to MySQL.
7. The Version 1 reference dataset represents a real-estate business that sells and rents condos, houses, land, and commercial properties. Reference data is synthetic and contains no real customer PII.
8. The LLM provider is OpenRouter and the configured model is `deepseek/deepseek-v4-flash-0731`.
9. `OPENROUTER_API_KEY` is supplied through the runtime environment only. Its value must never appear in source control, documentation, logs, container images, or test fixtures.
10. The deployment target is the existing GCP VM environment managed through Bangna Hos CLI. Exact project, zone, instance, network, and production identifiers remain deployment configuration and are not committed to this specification.
11. Version 1 uses a lean real-estate-first product experience for the current business: customer copy,
    Management navigation, forms, and result cards may use property/listing terminology. Internal models,
    API concepts, database tables, and compatibility routes remain domain-neutral catalog contracts; this
    decision does not introduce multi-tenancy or industry-specific orchestration branches.

### Confirmed LLM Configuration

- Provider API: OpenRouter.
- Model: `deepseek/deepseek-v4-flash-0731`.
- Runtime variable: `OPENROUTER_API_KEY`.
- The model name is configuration, not hard-coded policy logic.
- No automatic fallback to another provider or model is allowed in Version 1 unless the product owner approves it.
- OpenRouter failures must follow the safe failure and human-handoff rules in this specification; they must never produce a guessed business answer.

### Confirmed Deployment Target

- Platform: Google Cloud Platform virtual machine.
- Environment: existing Bangna Hos CLI-managed VM environment.
- Packaging target: one reviewed Docker Compose deployment bundle for the VM.
- Production secrets are injected on the VM and never committed.
- Build, push, migration, and deployment require separate product-owner approval.

### Confirmed Release Steward Workflow (approved 2026-09-12)

- **FR-OPS-001:** Use `development` as the integration branch, `staging` as the release-candidate branch,
  and `main` as the production source of truth.
- **FR-OPS-002:** A coding agent may inspect repository state, run approved checks, prepare commits,
  push reviewed work to `development`, fast-forward `staging` to an exact green `development` commit, and create or update
  a `staging` to `main` pull request. It must not merge `main`, deploy production, run production
  migrations, or issue credentials without a separate explicit product-owner approval.
- **FR-OPS-003:** Repository CI verifies Management backend tests, frontend typecheck/lint/build,
  the Python AI service tests/syntax, and the document-intake agent test suite without using Docker
  on the product owner's Mac.
- **AC-OPS-001:** One Release Steward command refuses a non-fast-forward promotion or a `development` commit
  without a successful CI run, advances `staging`, and maintains one production pull request.
- **AC-OPS-002:** Codex, Claude Code, Gemini CLI, and GitHub Copilot receive the same canonical
  repository rules through thin agent-specific instruction wrappers.
- **FR-OPS-004 (approved 2026-09-12):** Name the repository `business-omnichannel-ai` and use
  the full branch names `development` and `staging`. Organize shared documentation by purpose
  under `docs/`, source branding under `assets/branding/`, and installation examples under
  `examples/`. Preserve framework conventions, database migrations, public API paths and runtime
  service directories. Keep `SPEC.md` and agent discovery files at the repository root.

## 3. Users and Roles

### Customer

- Sends questions through LINE or WhatsApp.
- Receives grounded answers, catalog results, clarification questions, or a handoff acknowledgement.

### Human Agent

- Works in Chatwoot.
- Receives conversations routed to a configured team.
- Can respond without AI interference.
- Can explicitly return an eligible conversation to AI.

### Business Admin

- Uses Management to maintain catalog items, categories, prices, promotions, FAQs, policies, and knowledge entries.
- Publishes/unpublishes records and controls availability/effective dates.
- Does not edit prompts or deploy services to update normal business information.

### System Administrator

- Configures Chatwoot inbox/team IDs, API URLs, credentials, timeouts, and operational limits.
- Does not place secrets in source control.

## 4. System Ownership and Boundaries

| Concern | System of record | Allowed access |
|---|---|---|
| Conversations and messages | Chatwoot | Chatwoot public HTTP API and verified webhooks |
| Inbox, team assignment, status, `ai_mode` | Chatwoot | Chatwoot public HTTP API |
| Catalog, prices, availability, promotions | Management/MySQL | Authenticated Management API |
| FAQs, business policies, knowledge | Management/MySQL | Authenticated Management API |
| AI decisions, retrieval orchestration, dedup | AI orchestrator | In-process logic plus approved HTTP APIs |
| Secrets | Deployment secret store | Runtime injection only |

The legacy direct-LINE FastAPI implementation is not included. The Python service at `services/ai` receives Chatwoot events only and is the primary Version 1 AI conversation runtime.

## 5. Core Conversation Flow

1. LINE or WhatsApp delivers a customer message to Chatwoot.
2. Chatwoot creates/updates the conversation and sends a webhook to the AI orchestrator.
3. The orchestrator verifies webhook authenticity and reserves a stable deduplication key.
4. The orchestrator refetches live Chatwoot ownership and state.
5. A deterministic router chooses one action:
   - answer from FAQ/knowledge;
   - search the catalog;
   - ask one focused clarification question;
   - hand off to a human team;
   - ignore an ineligible event.
6. For catalog search, the orchestrator sends a validated structured query to Management.
7. The LLM receives only bounded, validated records and produces a grounded response.
8. Before sending, the orchestrator refetches ownership. If a human has taken over, it sends nothing.

## 6. Functional Requirements

### 6.1 Channel and Conversation Requirements

- **FR-CH-001:** LINE and WhatsApp conversations must appear in Chatwoot.
- **FR-CH-002:** The AI orchestrator must process verified Chatwoot webhook events only.
- **FR-CH-003:** Message deduplication must use a stable event identity that cannot be changed independently of the signed event content.
- **FR-CH-004:** Retries must not produce duplicate public replies.
- **FR-CH-005:** The AI must never compete with another direct LINE webhook on the primary path.

### 6.2 AI Eligibility and Ownership

- **FR-OWN-001:** AI may respond only when the configured inbox is allowed, the sender is the customer, `ai_mode` permits AI, and no human owns the conversation.
- **FR-OWN-002:** The orchestrator must refetch Chatwoot state before LLM work and immediately before a public reply.
- **FR-OWN-003:** Ownership lookup failure must fail closed and allow a safe retry.
- **FR-OWN-004:** A human-assigned, resolved, snoozed, or human-mode conversation must not receive an AI reply.
- **FR-OWN-005:** Return to AI must be explicit and auditable.

### 6.3 Management Knowledge

- **FR-KB-001:** Admins can create, edit, publish, unpublish, activate, and deactivate FAQs and knowledge entries.
- **FR-KB-002:** The read API returns active records only.
- **FR-KB-003:** Business policy and FAQ answers are versioned by `updated_at` and can be refreshed without redeploying the AI.
- **FR-KB-004:** API results use a stable versioned envelope and bounded pagination/limits.
- **FR-KB-005:** The orchestrator validates response schemas before adding records to an LLM prompt.

### 6.3a Business Profile

**Implemented.** A singleton `business_profile` record (Management/MySQL) lets a Business Admin edit the
business's own identity/tone as structured *data* -- `business_name`, `business_description`,
`services_offered`, `service_areas`, `business_hours`, `contact_channels`, `conversation_tone`,
`always_escalate_topics` -- without ever editing a prompt (SPEC §3: Business Admin does not edit prompts).
Read via `GET /api/v1/business-profile` (same `api.token:read` auth as other Management read endpoints),
managed via `apps/management/app/Http/Controllers/Admin/BusinessProfileController.php`.

The AI orchestrator fetches it through `cached_business_profile()` (`services/ai/src/ai_service/main.py`),
a process-local TTL cache (`BUSINESS_PROFILE_CACHE_TTL_SECONDS`, default 300s) with stale-if-error fallback
(FR-FAIL-002), and injects it into the LLM prompt as a separate `BUSINESS_PROFILE` block -- data, never
instructions (FR-AI-009, NFR-SEC-005). This satisfies AC-002 (edits apply after the cache TTL, no AI
redeploy) for identity/tone the same way it already held for catalog and knowledge.

- **FR-BP-001:** Identity/greeting/business-meta questions (e.g. "คุณคือใคร", "เปิดกี่โมง") route to a
  `smalltalk` intent (`is_smalltalk()`/`detect_intent()`) and are answered from `BUSINESS_PROFILE` alone --
  they must never be treated as a knowledge-base lookup, which previously produced an immediate
  `cannot_confirm` handoff on zero FAQ/knowledge results for exactly these questions.
- **FR-BP-002:** Catalog terms take priority over smalltalk terms when both could match, preserving
  existing catalog routing behavior unchanged.
- **FR-BP-003:** A smalltalk turn must not modify or clear an in-flight catalog conversation's saved
  filters/result IDs (`ai_catalog_filters`, `ai_last_catalog_result_ids`).

### 6.4 Domain-Neutral Catalog

The canonical concept is `Catalog Item`. Existing packages are one catalog item type; land and condos are other types.

- **FR-CAT-001:** Admins can manage catalog categories and catalog items.
- **FR-CAT-002:** Every catalog item has structured base fields:
  - `id`, `code`, `category_id`, `item_type`;
  - Thai name and optional English name;
  - Thai description and optional English description;
  - regular price, sale price, currency;
  - transaction type such as `sale`, `rent`, or `service` when applicable;
  - availability status;
  - searchable location text and optional province/district/subdistrict;
  - keywords/tags;
  - active, published, effective-from, effective-until;
  - created and updated timestamps.
- **FR-CAT-003:** Categories can define additional typed attributes with an allowlisted key, label, data type, unit, filter operators, and whether the attribute is searchable.
- **FR-CAT-004:** Catalog item attribute values are validated against their category definitions before save/import.
- **FR-CAT-005:** For real estate, supported category attributes include, when applicable:
  - project name;
  - bedrooms and bathrooms;
  - usable area in square metres;
  - land area in square wah;
  - floor;
  - property features.
- **FR-CAT-006:** Non-real-estate businesses may leave real-estate fields unused and define their own category attributes without changing orchestrator code.
- **FR-CAT-007:** Existing package records and `/api/v1/packages` remain usable during migration.

### 6.4a Lean Real-Estate Presentation Profile

- **FR-RE-001:** The current Management experience may label catalog items as properties/listings and
  prioritize real-estate fields, while internal `packages` storage and existing API routes remain backward
  compatible.
- **FR-RE-002:** A property carousel generated for an AI search must use the exact bounded item IDs returned
  by that search, in the same order. The carousel endpoint must independently recheck active, published,
  effective, and available state before rendering.
- **FR-RE-003:** If an exact property search has no result, the AI asks permission before relaxing filters.
  After consent it may drop location, price, and category-attribute constraints, but retains property category
  and sale/rent intent. If the relaxed search is also empty, it gives a deterministic no-result response and
  offers human assistance without asking the LLM to invent alternatives.
- **FR-RE-004:** A property may define one validated HTTPS primary image for the lean Version 1 card. An
  authenticated Management admin may upload that image through a short-lived Cloudflare Images Direct
  Creator Upload URL; the Cloudflare API token remains server-side and only the public delivery URL is
  stored with the property. The Catalog API and export/import contract carry that URL, and LINE Flex uses it
  as the hero image. Multi-image galleries and automatic deletion of replaced/orphaned hosted images remain
  outside this lean slice.
- **FR-RE-005:** The property import template includes structured category, sale/rent, availability,
  location, room, area, floor, and primary-image fields. Existing nine-column package files remain accepted
  and all imports continue to create unpublished drafts without overwriting duplicate codes.
- **FR-RE-006 (approved 2026-09-11):** Management may use Cloudflare R2 as an alternative primary-image
  storage provider without changing the stored catalog contract. Laravel generates a short-lived, single-
  object presigned PUT URL and returns only the required upload headers and configured public HTTPS URL to
  the authenticated admin browser. The R2 secret access key remains server-side, while the access key ID
  appears only as the standard signer identifier in the presigned URL. Credentials are scoped to the selected
  bucket and injected only at runtime. The bucket must allow the Management origin through bounded CORS and
  expose reads through a production custom domain; the `r2.dev` development URL is not a production target.
  Cloudflare Images remains supported for backward compatibility. Automatic deletion of abandoned,
  replaced, or orphaned objects remains outside this slice.

- **FR-RE-007 (approved 2026-09-11):** Create/edit catalog forms place primary-image editing
  first, then clearly grouped identity, price, location, specifications, advanced profile and
  publication controls. Add optional `map_url` (HTTPS, maximum 2048 characters) for Google
  Maps or another map provider, persisted independently of category/profile. Expose it through
  existing catalog resources and staff proposal validation without changing approval gates.
  Never fetch, embed or infer coordinates from the URL. Omitted updates preserve it; null clears it.
  Preserve import column contracts in this slice. Proposal tables keep badges/actions unbroken
  and scroll within their container at narrow widths. Development approval only; no production
  migration, deployment or CI/CD infrastructure change is implied.

### 6.5 Catalog Search API

- **FR-SEARCH-001:** Management exposes an authenticated, read-only catalog search operation at `POST /api/v1/catalog/search`.
- **FR-SEARCH-002:** The request accepts only validated structured filters. Proposed contract:

```json
{
  "query": "คอนโดบางนา",
  "category_slug": "condo",
  "transaction_type": "sale",
  "location": {
    "province": "กรุงเทพมหานคร",
    "district": "บางนา",
    "text": "บางนา"
  },
  "price": { "min": null, "max": 4000000 },
  "attributes": {
    "bedrooms": { "gte": 2 }
  },
  "availability": ["available"],
  "sort": "relevance",
  "limit": 10,
  "cursor": null
}
```

- **FR-SEARCH-003:** The server rejects unknown attributes, operators, sort values, oversized text, invalid ranges, and limits above the configured maximum.
- **FR-SEARCH-004:** The search implementation must use server-owned query builders. No SQL or database field names come from the LLM.
- **FR-SEARCH-005:** Results include only active, published, effective, and available records.
- **FR-SEARCH-006:** The response returns bounded result summaries, applied filters, result count, and an opaque next cursor when more results exist.
- **FR-SEARCH-007:** Management exposes `GET /api/v1/catalog/{id}` for a permitted item detail lookup.
- **FR-SEARCH-008:** Search and detail endpoints require a revocable read-only bearer token and rate limiting.

### 6.6 AI Catalog Query Behaviour

- **FR-AI-001:** The AI distinguishes informational questions from catalog-search intent.
- **FR-AI-002:** It extracts only supported filters and validates them before calling Management.
- **FR-AI-003:** If a missing detail materially changes results, it asks one concise clarification question. Example: sale versus rent. **Implemented for the knowledge zero-result path**: the first knowledge question with no matching FAQ/knowledge record gets one fixed clarification reply (`ZERO_RESULT_CLARIFICATION`) instead of an immediate handoff; `ai_zero_result_streak` in `custom_attributes` tracks this so a second consecutive empty-context miss in the same conversation still fails closed to `cannot_confirm` handoff rather than asking forever. Any real answer (catalog or knowledge) resets the streak.
- **FR-AI-004:** It may answer broad discovery questions such as “มีที่ดินที่ไหนบ้าง” with bounded grouped results and a follow-up filter question.
- **FR-AI-005:** It must not claim that an item exists, is available, or has a price unless the API returned that fact.
- **FR-AI-006:** Zero exact matches must be reported honestly. Filters may be relaxed only after telling the customer and receiving consent or presenting the relaxation explicitly.
- **FR-AI-007:** Results must show enough identity to continue the conversation: item name/code, location, relevant attributes, price/status, and a safe next action.
- **FR-AI-008:** The orchestrator must not load the entire catalog into the prompt. Default result limit is 10; the maximum is 20.
- **FR-AI-009:** Search filters and returned facts are treated as data, not instructions to the model.

### 6.7 Human Handoff

- **FR-HO-001:** Handoff triggers include explicit human requests, complaints, payment/refund problems, low-confidence or invalid AI output, and unavailable required data. Ordinary questions about payment methods or whether a price is negotiable remain AI-answerable when grounded data is available.
- **FR-HO-002:** Handoff first sets `ai_mode=human` and moves the conversation to the configured human state.
- **FR-HO-003:** The conversation is assigned to a configured Chatwoot team, not a hard-coded agent.
- **FR-HO-004:** A neutral public acknowledgement is sent only after the AI lock succeeds.
- **FR-HO-005:** Private notes use deterministic templates and must not include raw LLM reasoning.
- **FR-HO-006:** Return to AI clears incompatible human ownership, sets the configured AI state, and records the action. **Implemented**: staff apply the `ส่งกลับ-ai` Chatwoot label to a conversation; the AI service reacts to the resulting `conversation_updated` webhook (`_process_conversation_updated` in `services/ai/src/ai_service/main.py`), refetches live state under the conversation lock, unassigns the individual agent, sets `ai_mode=ai`, clears both `ส่งกลับ-ai` and the `คนดูแลอยู่` handoff-state label, and logs a private audit note. A stale/duplicate label event (already `ai_mode=ai`) is a no-op, which also prevents the write from re-triggering itself.

### 6.8 Failure Behaviour

- **FR-FAIL-001:** A short Management API timeout is required and must be bounded by the overall response deadline.
- **FR-FAIL-002:** Cached knowledge may be used within a documented stale-if-error window.
- **FR-FAIL-003:** Availability and price-sensitive results must identify stale data internally and must not be presented as confirmed-current beyond the allowed stale window.
- **FR-FAIL-004:** If no safe source exists, the AI says it cannot confirm and offers handoff; it never guesses.
- **FR-FAIL-005:** AI transport failure returns a retryable failure without locking the customer into human mode unless deterministic policy independently requires handoff.

### 6.9 AI Connection Setup (approved 2026-09-05)

**FR-KNOWLEDGE-UI-001 (approved 2026-09-11):** Knowledge index supports responsive cards
and the existing table, selectable through a labeled view toggle. Default to cards; preserve
view in search/filter/pagination URLs. Cards show title, plain-text body excerpt, type,
category, version, review date, status and existing authorized actions. No data or permission
changes, no new dependencies; development verification only, not production deployment.

**FR-DOC-RETIRE-001 (approved 2026-09-11):** Retire the Management PDF upload/document
administration UI at `/admin/documents`, including navigation, detail and mutation endpoints.
Authenticated administrator requests to the retired path return 410; authentication and staff
boundaries remain. Remove the upload/lifecycle implementation and replace its obsolete tests
with retirement/non-mutation tests. Preserve existing private files, DocumentSource records and
proposal evidence. No database/storage purge or production deployment is authorized by this
change. External AI clients read customer PDFs and submit structured proposals; existing
metadata read APIs/MCP remain compatible for historical sources. This supersedes earlier
document-upload UI instructions, not the catalog proposal or AI connection capabilities.

- **FR-SETUP-001:** Management provides an admin-only Thai setup page for CLI, Codex, Claude Code, ChatGPT Work and Claude Cowork, explaining verified capabilities and prerequisites per client.
- **FR-SETUP-002:** Admins can issue document-metadata-only keys with a default lifetime of 60 minutes; allow 15, 60 or 240 minutes. Keys are hashed at rest, returned once in a no-store JSON response, never embedded in URLs, page props, browser storage or generated shell commands.
- **FR-SETUP-003:** The page shows expiry and recent keys with immediate revocation. Expired keys are rejected server-side, with no automatic lifetime extension. Issuance is rate-limited and protected by admin session and CSRF.
- **FR-SETUP-004:** Instructions distinguish existing stdio document_list/document_get from remote MCP. ChatGPT Work/Cowork must not display a working connector URL until a compatible authenticated remote transport is implemented and verified. Existing tooling does not read PDF contents or modify catalog records.
- **FR-SETUP-005 (approved 2026-09-06):** Publish a credential-free document-intake SKILL.md and llms.txt index. Setup provides open/download links and a copyable prompt containing only the public skill URL. Explain actual tool limits and missing cloud transport; reading a skill does not authenticate or install a connector.
- **FR-SETUP-006 (approved 2026-09-06):** Default to Agent setup with a per-client, visible copyable installation prompt, public installation guide and downloadable credential-free CLI/MCP package. Keep Manual setup available. Separate operator-entered keys from prompts and show verification steps without simulated connection success. Cloud clients remain unavailable until FR-SETUP-004 is satisfied.
- **FR-SETUP-007 (approved 2026-09-06):** Publish a readable HTML documentation page with section navigation, copyable examples, mobile layout and machine-readable links. Add Antigravity local stdio setup with placeholder-only JSON, operator-entered credentials in private global config, scoped expiring setup keys and explicit verification limits. Preserve existing clients and Markdown URLs.

### 6.9.1 Browser-authorized remote MCP (approved 2026-09-11)

- **FR-SETUP-008:** Add a remote HTTP MCP transport using maintained Laravel MCP and
  Passport authorization-code/PKCE support. The user enters existing credentials only
  on Management's login page, then explicitly approves named scopes. Login alone is
  not authorization. Never ask the AI to collect passwords, cookies or bearer keys.
- **FR-SETUP-009:** Start with document metadata and explicitly scoped read/proposal
  capabilities. Preserve FR-AGENT-004/005/006: no remote approve, apply, publish, SQL or
  PDF download. Enforce active administrator ownership, token expiry, revocation and
  account security-version changes on every call. Keep stable proposal ownership
  across token refresh. Use short-lived access tokens and bounded refresh lifetime.
- **FR-SETUP-010:** Default AI Setup to simple browser connection instructions; keep
  local keys/CLI in Advanced. Provide credential-free Copy prompt, documentation,
  connection history and revoke controls. Do not claim that copying a prompt installs
  any client or proves connectivity. Expose compatibility limits honestly. Development
  and synthetic tests run on the existing GCP dev VM; production enablement and real
  client/account compatibility require separately recorded verification.

### 6.9a External staff agent data proposals (approved 2026-09-06)

The product owner approved `.hermes/plans/2026-09-06_150803-agent-managed-catalog-crud.md`.
Implement and review one vertical slice at a time. This approval covers development on the
existing VM, not production migration/deployment, live credential issuance or a real-data pilot.
FR-SETUP-002/004 describe the existing read-only package: preserve it until an explicitly
versioned opt-in proposal client is delivered. Customer-facing AI remains read-only.

- **FR-AGENT-001:** Keep MySQL and existing domain tables. Extend catalog with validated category
  definitions/schema versions and, in the next schema slice, group/variant/offer, parent references,
  optimistic versions and recoverable archive. No arbitrary SQL, table creation or schema activation by AI.
- **FR-AGENT-002:** Shared validation supports string, integer, decimal, boolean, enum, string_list and
  numeric_range, bounded keys/values, canonical units and explicit nullable values. Missing fields are
  not invented. Reject unknown keys and core-field duplication. Keep legacy definitions/data unchanged;
  typed definitions are opt-in and incompatible conversion requires a separately reviewed migration.
- **FR-AGENT-003:** Bounded per-field evidence links to private DocumentSource records when available;
  external-only references are unverified. External clients read PDFs; Management does not add OCR/LLM
  infrastructure. Never infer stock, price or business policy from missing brochure data.
- **FR-AGENT-004:** Immutable change sets support create/update/archive/restore, preview, submission,
  status, idempotency and transactional all-or-none apply with version/schema revalidation. Preview and
  proposal submission never change live business records. Record protected source/revision history.
- **FR-AGENT-005:** Separate opt-in staff agent abilities from customer read keys. No automatic permission
  upgrade. Keys cannot approve/apply/publish. Expiry stops new requests; accepted proposals use current
  admin authority. Security revocation suspends pending proposals until trust review.
- **FR-AGENT-006:** Admin session plus CSRF approves one immutable batch with visible diff, sources and
  issues. Creates are drafts; publish requires separate explicit confirmation. Updates to published data
  disclose immediate customer impact. Agent-supplied confirmation never bypasses the server gate.
- **FR-AGENT-007:** Preserve old API, document tools, legacy forms and 9/23-column imports. Typed catalog
  import/export must roundtrip JSON without coercion. Groups/variants/archived rows must not become
  inventory results. Only allowlisted query fields/operators may be searched.
- **FR-AGENT-008:** Deliver catalog first, then FAQ/knowledge adapters. Each slice includes synthetic tests,
  compatibility and privacy evidence, review notes and unverified items. MySQL concurrency, client pilots,
  backup/restore and rollout gates remain mandatory before production enablement.

### 6.9b Optional catalog profiles (approved 2026-09-11)

The product owner requested a structured `property_project` profile and a COCO PARC
brochure-based draft example. This slice covers development and isolated sample validation.

- **FR-PROFILE-001:** Add nullable, domain-neutral `profile` and `profile_data` fields on
  catalog records. Server-owned, versioned profiles are opt-in and independent of category
  attributes. `property_project` belongs to a group; `property_layout` describes a variant's
  room type and canonical sqm range. Reuse core name/location fields, and existing source history.
  Do not add property-specific database columns or rewrite existing category definitions.
- **FR-PROFILE-002:** Validate bounded profile fields across model, admin and agent writes.
  Reject unknown profiles/keys, mismatched record kinds, invalid dates/coordinates/counts,
  nonstandard facility values and reversed ranges. Omitted values stay unknown. Profile data
  replaces the whole profile object when supplied; omission on update preserves it.
- **FR-PROFILE-003:** Staff API/MCP exposes the profile schema, version, allowed fields and
  bounded exact filters for profile, record kind, parent and facility. Project facts come from
  groups, layout ranges from variants, and price/availability only from actual offers.
  Offers may attach directly to a group or to a variant. Preserve customer inventory eligibility
  and the existing human approval/publication gates; customer group-retrieval integration is a
  separate slice, not claimed by this schema-first delivery.
- **FR-PROFILE-004:** Provide a page-referenced, nonpublished COCO PARC proposal example,
  validated through preview/submit/admin apply in isolated tests. Do not infer current construction
  status, price, vacancy, coordinates, or source dates from missing or hidden brochure text.
  Include review documentation and honest retrieval/deployment limits.

### 6.10 Management Users (approved 2026-09-06)

- **FR-USER-001:** Admins manage bounded, paginated staff accounts with name, unique email, role (admin/editor/viewer) and enabled state. No hard deletion or tenant provisioning. Preserve existing administrators and deny legacy non-admin accounts without an explicit staff role.
- **FR-USER-002:** Enforce roles server-side: editors maintain business content, viewers read it, and only admins manage users, documents and credentials. Disabled accounts cannot log in or reuse a session. Serialize account changes and never remove the final enabled administrator.
- **FR-USER-003:** Admins generate a one-time, expiring password-setup link through a no-store response, to share privately. Passwords are hashed; links/passwords are never audit data or page props. Email delivery requires separately configured mail transport and is not claimed by this slice.
- **FR-USER-004:** Newly web-issued API/MCP keys have an owner. Disabling or changing a user's role revokes their owned keys and invalidates sessions. Legacy ownerless service keys remain untouched and are documented explicitly.
- **FR-USER-005:** Record user-management events with actor ID, target ID, event and timestamp, without passwords, links or names/emails in audit payloads. Tests cover authorization, validation, final-admin protection, disabled sessions, reset expiry/reuse and credential ownership. Catalog-wide revision history remains a separate slice.

## 7. State Model

### AI Active

- `ai_mode=ai`
- configured AI-eligible Chatwoot status
- no individual human assignee
- configured inbox is allowed

### Human Active

- `ai_mode=human`
- configured human status, normally `open`
- assigned to the configured team
- carries the `คนดูแลอยู่` Chatwoot label (visible in the conversation list without opening custom attributes)
- AI ignores new customer messages until explicit return

### Transition Rules

- AI Active → Human Active: deterministic handoff or validated AI handoff action.
- Human Active → AI Active: explicit staff/admin action only, via the `ส่งกลับ-ai` Chatwoot label (see FR-HO-006).
- Any ambiguous or failed transition: remain/fail closed in the safer human-owned state.

## 8. Non-Functional Requirements

### Security and Privacy

- **NFR-SEC-001:** No secrets or production identifiers in source control.
- **NFR-SEC-002:** No customer content, PII, raw prompts, raw LLM output, or private notes in application logs.
- **NFR-SEC-003:** API tokens are hashed at rest, revocable, expirable, and scoped to required abilities.
- **NFR-SEC-004:** Webhook verification and replay protection happen before LLM or upstream API work.
- **NFR-SEC-005:** All LLM-facing text is length-bounded and separated from system instructions.

### Reliability

- **NFR-REL-001:** Duplicate delivery must produce at most one public reply.
- **NFR-REL-002:** Knowledge/catalog API failures degrade to bounded cache or safe handoff.
- **NFR-REL-003:** Ownership races fail closed.
- **NFR-REL-004:** Migrations are additive and reversible before destructive cleanup is considered.

### Observability

- **NFR-OBS-001:** Allowed metadata includes event type, delivery ID, conversation/account IDs, action, status, duration, error class/code, and reason category.
- **NFR-OBS-002:** Metrics distinguish answer, clarification, catalog search, zero result, handoff, ignored event, retryable failure, and upstream timeout.
- **NFR-OBS-003:** Logs and metrics must not contain message bodies or catalog records with sensitive fields.

## 9. Version 1 Exclusions

- Multi-business tenant isolation, tenant billing, and tenant provisioning.
- Booking, appointment scheduling, calendar integration, or availability reservation.
- Payment processing, payment links generated by AI, refunds, or financial transactions.
- Autonomous catalog modification by AI.
- Direct SQL generated by or exposed to the LLM.
- Vector database or semantic retrieval infrastructure unless separately approved after measured need.
- Public customer storefront, CRM replacement, or Chatwoot replacement.
- Teacher/student/language-school workflows.

## 10. Acceptance Criteria

- **AC-001:** A LINE or WhatsApp customer message reaches Chatwoot and triggers at most one eligible AI processing attempt.
- **AC-002:** Updating an active FAQ or catalog item in Management affects AI answers after the configured cache TTL without an AI redeploy.
- **AC-003:** Unpublished, inactive, expired, or unavailable catalog items never appear as available results.
- **AC-004:** “มีที่ดินที่ไหนบ้าง” returns bounded results sourced from Catalog Search API or honestly reports no match.
- **AC-005:** “คอนโด 2 ห้องนอน งบไม่เกิน 4 ล้านบาท” produces validated category, price, and bedroom filters and returns only matching API records.
- **AC-006:** Unknown or malicious attribute/filter input cannot become arbitrary SQL or an unrestricted database query.
- **AC-007:** Zero exact results do not cause invented listings or silently relaxed filters.
- **AC-008:** An explicit request for a human locks AI and routes the conversation to the configured Chatwoot team.
- **AC-009:** After human takeover, concurrent or later webhook retries do not send an AI public reply.
- **AC-010:** Return to AI requires an explicit action and restores only the configured AI state.
- **AC-011:** Management API outage uses permitted cache or gives a safe cannot-confirm/handoff response.
- **AC-012:** No active runtime message or decision assumes a teacher, student, lesson, or language school.
- **AC-013:** Relevant management backend tests, frontend typecheck/lint/build, and orchestrator tests/typecheck/build pass where repository rules permit execution.
- **AC-014:** Delivery documentation lists all configuration, migrations, verification evidence, known limitations, and production steps not executed.
- **AC-015:** Search text and any LINE property carousel are grounded in the same ordered catalog item IDs;
  an ineligible ID is omitted even if supplied by the orchestrator.
- **AC-016:** Zero exact property results never trigger an automatic broad recommendation. Relaxed results
  appear only after explicit customer consent and retain category plus sale/rent intent.
- **AC-017:** An authenticated admin can obtain a short-lived Cloudflare Images or R2 upload URL and upload
  one primary property image without receiving a provider secret. The resulting HTTPS delivery URL is returned by
  Catalog API and rendered in Flex; an HTTP image URL is rejected. Empty property specs use neutral copy
  rather than invented property qualities.
- **AC-018:** The current property template imports structured search/card fields as a draft, while the
  legacy nine-column template remains accepted.

## 11. Implementation Sequence

Codex must implement only one product-owner-approved slice at a time. No work is delegated to `agy`:

1. **Catalog contract:** schema design, category attribute definitions, migrations, model validation, and API contract tests.
2. **Management catalog UI:** CRUD, filters, publish/availability controls, and import/export updates.
3. **Catalog Search API:** validated filters, detail lookup, limits, auth, and rate limiting.
4. **AI catalog tool:** intent routing, filter extraction/validation, API client, clarification, zero-result behavior, and bounded presentation.
5. **Generic handoff:** team-based routing, deterministic messages, explicit Return to AI, and race tests.
6. **End-to-end verification:** LINE/WhatsApp through Chatwoot using non-production fixtures and no real customer data.

Each slice requires tech-lead review before the next slice begins.

## 12. Production Readiness Gate

Passing unit tests is not proof of production readiness. Production enablement additionally requires:

- a reviewed Management database migration and rollback plan;
- a real read-only API token stored outside source control;
- verified private network connectivity between the orchestrator and Management;
- configured Chatwoot inbox and team IDs;
- webhook authenticity and retry tests;
- representative catalog data quality review;
- log privacy review;
- controlled rollout with a rollback path.
 a rollback path.

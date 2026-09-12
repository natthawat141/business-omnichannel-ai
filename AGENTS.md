# Business Omnichannel AI — Agent Instructions

## Authority and Source of Truth

- The user is the product owner and retains final decision authority.
- Codex acts as tech lead: clarify requirements, maintain `SPEC.md`, review designs, and verify delivery evidence.
- Codex implements approved slices of `SPEC.md` directly. Do not delegate work to `agy`.
- `SPEC.md` is the canonical product and technical contract for Version 1.
- If code, README files, or older docs conflict with `SPEC.md`, stop and report the conflict. Do not silently choose a different product direction.
- Do not begin implementation while the relevant `SPEC.md` requirement is marked unresolved or awaiting product-owner approval.

## Product Overview

- This product is a single-business omnichannel AI assistant for customer conversations over LINE and WhatsApp through Chatwoot.
- Chatwoot owns conversations, inboxes, assignment, message history, and AI/human state.
- `apps/management` and its MySQL database own business knowledge and structured catalog data.
- The Chatwoot AI orchestrator reads business data through authenticated HTTP APIs. It never accesses the management database directly.
- The system must work for general businesses. Real estate is a required reference use case: customers can ask which land plots or condos are available and filter them by location, price, transaction type, and category-specific attributes.

## Confirmed Version 1 Boundaries

- One business only; no SaaS multi-tenancy.
- Human handoff routes to a Chatwoot team, never to a hard-coded individual agent.
- No booking, appointment scheduling, calendar integration, or payment execution.
- No teacher, student, lesson, tutor, classroom, or language-school behavior in active runtime paths.
- No second LINE webhook may compete with Chatwoot as the primary channel path.
- The legacy direct-LINE `line-bot-service` is not included. `services/ai` is the only Python conversation runtime and receives events from Chatwoot only.

## Repository Map

- `apps/management/` — Laravel 13 + Inertia/React admin, MySQL source of truth, authenticated read APIs, imports/exports, and analytics sink.
- `apps/management/routes/api.php` — management API routes.
- `apps/management/app/Http/Controllers/Api/KnowledgeApiController.php` — current read API behavior.
- `apps/management/database/migrations/` — additive database migrations.
- `services/ai/` — Python/FastAPI Chatwoot AI orchestrator. It must remain fail closed until webhook verification, ownership checks, deduplication, and handoff are implemented and tested.
- `infra/chatwoot/` — pinned Chatwoot CE web/worker, PostgreSQL, and Redis container definitions. This is the canonical local Chatwoot scaffold.

## Technology and Commands

From `apps/management/`:

- Install PHP dependencies: `composer install`
- Install frontend dependencies: `npm install`
- Backend tests: `php artisan test`
- Frontend typecheck: `npm run typecheck`
- Frontend lint: `npm run lint`
- Frontend build: `npm run build`

From `services/ai/`:

- Install: `python3 -m pip install -e '.[dev]'`
- Tests: `pytest`
- Syntax check: `python3 -m compileall -q src tests`

From `infra/chatwoot/`:

- Validate: `docker compose config`
- Prepare database: `docker compose run --rm rails bundle exec rails db:chatwoot_prepare`
- Start: `docker compose up -d`

If a repository instruction forbids executing a command, do not bypass it. Report the required command as not run and explain the governing rule.

## Architecture Rules

### Ownership

- Chatwoot is the source of truth for conversation status, inbox, assignment, `ai_mode`, and message history.
- Management/MySQL is the source of truth for catalog items, prices, promotions, FAQs, policies, and knowledge entries.
- The AI orchestrator owns request orchestration, safe prompt construction, deterministic policy checks, deduplication, retries, and handoff execution.
- Never duplicate conversation ownership in MySQL or business-data ownership in Chatwoot custom attributes.

### Catalog and Retrieval

- Treat `Catalog Item` as the domain-neutral business concept. A catalog item may be a service package, product, land plot, condo, or another sellable/listable item.
- Do not model real estate by placing all facts in a Markdown knowledge entry. Searchable facts must be structured and validated.
- The AI must query the Management Catalog API. It must never generate SQL or receive database credentials.
- Only allowlisted search fields and category attribute definitions may become filters.
- Search endpoints must return only active, published, effective, and available records.
- Keep existing `/api/v1/packages`, `/faqs`, and `/knowledge` contracts backward compatible unless an approved migration explicitly versions or retires them.
- Never load an unbounded catalog into the LLM prompt. Use bounded search results and fetch item detail only when needed.
- The AI must not silently loosen filters. If no exact match exists, say so and ask permission before showing nearby alternatives.

### Chatwoot and Handoff

- AI replies only after a live ownership check confirms the conversation is AI-eligible.
- On handoff, lock AI first, route to a configured Chatwoot team, and then send the public acknowledgement/private note.
- If the lock or ownership refetch fails, fail closed. Do not send an AI reply that may race with a human.
- Return to AI must be an explicit action and must not happen automatically because of a new customer message.
- Team IDs, inbox IDs, URLs, tokens, and policy thresholds belong in validated configuration, never hard-coded business logic.

### Domain Neutrality

- Use neutral terms such as customer, staff, human agent, catalog item, inquiry, and handoff.
- Legacy identifiers may remain temporarily for compatibility only when they do not change behavior and are documented as migration debt.
- Do not add industry-specific workflow logic to the shared orchestrator. Industry-specific facts belong in catalog attributes, knowledge, and policy configuration.

## Security and Privacy

- Never commit `.env` files, credentials, bearer tokens, private keys, auth URLs, or production identifiers.
- Store API tokens hashed on the management side; plaintext is shown only at issuance and stored in a secret manager at deployment time.
- Never log customer message bodies, PII, raw LLM output, prompts, private notes, tokens, or raw Chatwoot/database logs.
- Validate webhook authenticity, timestamps, and deduplication identity before running the LLM.
- Validate every Management API request and response with bounded sizes, limits, and schemas.
- Public AI replies must be grounded in returned records. Never invent inventory, price, availability, promotion, or policy facts.

## Change Workflow for Codex

1. Read `SPEC.md`, this file, the target repository's nearest `AGENTS.md`, and relevant README/architecture docs.
2. Inspect `git status` and preserve all pre-existing user changes.
3. State the requirement IDs being implemented and list expected files before editing.
4. Make the smallest coherent vertical slice. Use additive migrations and backward-compatible API changes.
5. Add tests for success, zero results, invalid filters, unavailable upstreams, ownership races, and security boundaries affected by the slice.
6. Update canonical Markdown docs in the same change when contracts, configuration, state, or behavior change.
7. Run all checks permitted by repository instructions.
8. Report changed files, commands and results, unverified items, risks, and required production configuration.

## Stop Conditions

Stop and request product-owner/tech-lead direction before:

- changing a confirmed Version 1 boundary;
- introducing multi-tenancy, booking, payments, direct database access, a vector database, an application-owned queue/Redis dependency, or another public webhook path (Chatwoot's required PostgreSQL, Redis, Rails, and Sidekiq services are already approved infrastructure);
- deleting or destructively migrating existing data;
- changing production infrastructure, deploying, pushing, or issuing real credentials, unless the product owner has explicitly authorised the exact environment and action;
- choosing between incompatible API/data migration strategies with material user impact;
- overwriting user changes or secrets.

## Definition of Done

An implementation slice is done only when:

- its approved `SPEC.md` acceptance criteria are satisfied;
- tests cover the changed behavior and permitted checks pass;
- API and configuration documentation match the implementation;
- no secret, PII, or unbounded data is introduced into logs or prompts;
- backward compatibility and migration effects are documented;
- the handoff clearly separates verified results from assumptions and unverified production behavior.

## Release Steward

- The canonical branch and promotion contract is `docs/BRANCHING.md`; the operating playbook is
  `docs/RELEASE_STEWARD.md`.
- A coding agent may manage routine integration work allowed by FR-OPS-001–003: inspect CI, prepare
  review evidence, push approved work to `dev`, run the Release Steward workflow, and maintain the
  `stg` to `main` pull request.
- A coding agent must stop before merging `main`, deploying production, running production
  migrations, changing production infrastructure, or creating real credentials. Those actions still
  require a separate explicit product-owner approval.
- Never let two agents edit the same files concurrently. The primary agent owns the final diff,
  verification, and handoff even when another agent performs a bounded review.

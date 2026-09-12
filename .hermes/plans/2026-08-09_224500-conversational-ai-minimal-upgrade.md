# Conversational AI Minimal Upgrade Plan

## Status

Implemented and validated locally. No commit, push, deployment, or production secret change was
performed in this work session.

## Decision

Approve the direction of `docs/archive/conversational-upgrade-spec.md`, with the amendments in
this plan. The proposal targets the right causes of a bot-like experience: missing conversation
history, irrelevant knowledge retrieval, imprecise handoff detection, and catalog follow-up state.

Do not implement the proposal verbatim because parts of it conflict with the current checkout and
would leave reliability gaps:

- Redis is already the approved runtime queue in `services/ai/src/ai_service/main.py` and
  `services/ai/src/ai_service/worker.py`. The non-goal is therefore “no new infrastructure”; do not
  remove or duplicate the existing queue.
- The webhook still enqueues to Redis and returns `202`; the worker owns execution. A small
  `background_task()` helper is retained only as a safe utility for any future detached task and
  is not used for the webhook path.
- Chatwoot custom-attribute writes must use `merge: true` for incremental state. Chatwoot's API
  replaces the whole attributes hash when `merge` is omitted; without this correction, adding
  conversational state can erase `ai_mode` or other state.
- A simple full-string `LIKE` search plus a top-row fallback is not sufficient retrieval. The
  fallback must not inject unrelated records into a prompt for a specific question.
- The optional artificial response delay is excluded. It increases latency and does not improve
  reasoning or grounding.

## Goal

Make the assistant feel continuous and useful in a real business conversation while preserving the
Version 1 safety contract:

1. Resolve references such as “ตัวแรก”, “อันนั้น”, and “แล้วถ้าเช่าล่ะ”.
2. Preserve compatible catalog filters across turns and reset them when the customer changes topic.
3. Retrieve relevant FAQ/knowledge records instead of the first database rows.
4. Reply in natural Thai or the customer's language without repeated greetings or canned closings.
5. Never invent business facts, race a human agent, or create duplicate public replies.

## Current context and evidence

- `main.py:25,163-165` uses broad substring handoff terms, so ordinary questions containing “คน”,
  “ชำระ”, or “จ่ายเงิน” can be misclassified.
- `main.py:134-136` fetches the first FAQ and knowledge rows without a query.
- `main.py:238-252` sends the current question and records as one prompt and has no conversation
  history or role structure.
- `main.py:287-308` has a second ownership check, but marks `ai_last_message_id` before the
  public POST; this is the wrong completion state for a duplicate-safe flow.
- `main.py:98-108` writes Chatwoot custom attributes without the merge flag.
- `worker.py:19-51` already owns event retries and dead-letter behavior. New inner retries must not
  multiply the worker's three attempts into an uncontrolled retry storm.
- `KnowledgeApiController.php:83-140` has bounded active-only endpoints but no `q` search.
- `SPEC.md:99-111, 117-129, 202-227` already requires history-aware ownership, bounded grounding,
  clarification, and safe handoff behavior.

## Scope boundary

Included:

- Existing Docker/Redis runtime; no new service, database, vector store, queue, or framework.
- One LLM call per incoming message.
- Chatwoot conversation custom attributes for small state, with explicit size and expiry limits.
- Deterministic routing, filter extraction, reference resolution, and handoff classification.
- Source-grounded prompt construction and sanitized operational logs.

Excluded:

- Vector DB, embeddings, LangChain/LlamaIndex, agent frameworks, Kafka/Celery/RabbitMQ,
  distributed locks, LLM summarization, multi-message replies, or a new session database.
- Broad `main.py` module refactoring in this slice.
- Booking, payment execution, multi-business SaaS, or direct database access from AI.

## Implementation sequence

### Preflight: contract and fixture gate

Before changing behavior, create sanitized fixtures from the current Chatwoot/Management response
shapes or use mocked responses based on the checked-out API contract. Confirm `message_type` values,
message ordering, custom-attribute response shape, and catalog detail envelope. Do not copy customer
messages or PII into the repository.

Record the current baseline for 20 non-production conversations with only redacted text and IDs. Score:

- directness: answers the customer's question;
- grounding: no invented price, availability, policy, or property fact;
- conversational tone: reads like a helpful business representative, not a canned bot.

### Slice 1: precise handoff and safe attribute writes

Files:

- `services/ai/src/ai_service/main.py`
- `services/ai/tests/test_handoff.py` (new)
- `services/ai/tests/test_health.py` (extend only where existing fixtures are appropriate)

Work:

1. Replace single-word substring matching with normalized explicit customer-request, complaint,
   and payment-problem phrases. Keep ordinary “อยู่ได้กี่คน”, “ชำระเงินยังไง”, “ผ่อนได้ไหม”, and
   “ต่อรองราคาได้ไหม” in AI/knowledge handling.
2. Keep the handoff reason as a fixed enum/category. Build private notes from deterministic templates;
   never pass free-form LLM reasoning into Chatwoot notes.
3. Change the Chatwoot custom-attribute client to send `merge: true` for incremental writes. Add a
   focused test proving that writing one AI state key does not erase `ai_mode` or another key.
4. Preserve the existing order: lock AI/handoff state first, assign the shared Chatwoot team, then
   send acknowledgement and deterministic private note.

Acceptance:

- Ordinary people-count, payment-information, and installment questions do not hand off.
- Explicit human request, complaint, and payment failure/refund do hand off. Ordinary negotiation
  questions remain AI-answerable when business data can support the answer.
- The handoff team remains team-based, never an individual agent.
- Custom-attribute updates merge rather than replace unrelated state.

### Slice 2: relevant knowledge retrieval

Files:

- `apps/management/app/Http/Controllers/Api/KnowledgeApiController.php`
- `apps/management/tests/Feature/KnowledgeApiTest.php`
- `services/ai/src/ai_service/main.py`
- `services/ai/tests/test_health.py` or a focused client test in the existing test tree

Work:

1. Add bounded `q` support to FAQ and knowledge endpoints using verified column names and escaped
   search input. Preserve active-only filtering, rate limits, and the existing envelope.
2. Normalize polite suffixes and filler before the bounded request. For example,
   “ชำระเงินยังไงครับ” produces the meaningful query “ชำระเงิน”.
3. Keep a bounded `limit=5` per source and combine at most 10 records.
4. Do not automatically fall back to unrelated first rows when a specific search returns zero. For
   broad discovery questions, use an explicit bounded browse path; otherwise return a safe clarification
   or cannot-confirm response.
5. Add tests for matching terms, escaped wildcards, empty query compatibility, inactive records, and
   zero-result behavior.

Acceptance:

- “ชำระเงินยังไงครับ” queries relevant FAQ/knowledge records and does not hand off.
- A specific zero-result query never receives arbitrary first-row context.
- Existing callers without `q` remain backward compatible.

### Slice 3: conversation history and natural response policy

Files:

- `services/ai/src/ai_service/main.py`
- `services/ai/src/ai_service/history.py` (new)
- `services/ai/tests/test_history.py` (new)

Work:

1. Add `ChatwootClient.messages()` using the verified `/messages` response shape and bounded
   pagination. Sort by `created_at` if the deployment response is not chronological.
2. Filter private notes, activity/system/template messages, blank content, and attachment-only
   messages. Map incoming message type to `user` and public outgoing messages to `assistant`.
3. Keep at most 10 messages and 8,000 characters. Ensure the current webhook message is not added
   twice when Chatwoot already returned it.
4. Pass real role-separated messages to OpenRouter: system policy, bounded business context,
   filtered history, and current user message.
5. Use a concise Thai-first conversational policy: answer first, no repeated greeting, no repeated
   question, one clarification at a time, no invented facts, and one useful next action. Treat both
   customer history and business context as untrusted data, never as system instructions.
6. Keep temperature at `0.2-0.3`; do not use temperature as a substitute for retrieval or state.
7. Do not add artificial sleeps. If typing indicators are ever needed, treat that as a separate
   Chatwoot UX task.

Acceptance:

- Follow-ups understand the prior turn without a repeated greeting.
- Private notes and system activity never enter the model context.
- Replies remain short and natural while all business facts are sourced from returned records.
- No customer content, prompt, history, or raw model output is logged.

### Slice 4: catalog conversation state and references

Files:

- `services/ai/src/ai_service/main.py`
- `services/ai/src/ai_service/tests` only if a new focused test location is required; prefer
  `services/ai/tests/test_catalog_state.py` (new)

Work:

1. Store only bounded JSON strings in Chatwoot attributes: `ai_last_intent`,
   `ai_catalog_filters`, `ai_last_catalog_result_ids`, and `ai_context_updated_at`.
2. Read malformed or oversized state as empty and enforce a hard 2-4 KB state budget.
3. Detect catalog follow-ups deterministically. Merge filters with explicit semantics: scalar
   fields replace, nested filter keys merge, and reset phrases remove selected filters or all state.
4. Add a context TTL. Recommended default is 24 hours or until a resolved conversation is reopened;
   after expiry, “เอา 2 ห้องนอน” must not inherit an old property search. Make the TTL configurable.
5. After a successful search, store at most 10 result IDs. Resolve “ตัวแรก/ตัวที่สอง/ตัวสุดท้าย”
   deterministically and call `GET /api/v1/catalog/{id}` for exact facts. Do not ask the LLM to guess
   which item an ordinal refers to.
6. Preserve catalog state when a knowledge question is asked in the middle of a catalog conversation,
   but update `ai_context_updated_at` consistently. Use `merge: true` on every state update.
7. Verify catalog detail responses separately from list responses; do not force object payloads through
   the current list-only parser.

Acceptance:

- The four-turn condo example keeps location and budget, adds bedrooms, resolves the first item, and
  changes only transaction type when the customer asks to rent.
- “เริ่มใหม่”, “ไม่จำกัดงบ”, and a changed category reset the expected state.
- State is not reused after the configured TTL.
- Knowledge questions do not erase catalog filters.

### Slice 5: delivery safety, deadline, and observability

Files:

- `services/ai/src/ai_service/main.py`
- `services/ai/src/ai_service/worker.py`
- `services/ai/tests/test_health.py` and focused integration tests
- `.env.example`, `services/ai/.env.example`, `README.md`, `docs/architecture/overview.md`, and `SPEC.md`
  when configuration or behavior changes

Work:

1. Keep the second Chatwoot ownership refetch immediately before public send.
2. Write `ai_completed_message_id` only after a confirmed successful Chatwoot message response.
   Never blindly retry `POST /messages` after an ambiguous timeout; emit a sanitized
   `delivery_unknown` result for later reconciliation.
3. Reconcile the existing worker-level retry policy with any read/LLM retry. Keep a single overall
   processing deadline (recommended 25 seconds) and do not multiply three outer event retries by
   three inner retries.
4. Add configurable Management, Chatwoot, and OpenRouter timeouts. Classify failures in sanitized
   metadata such as `management_timeout`, `llm_rate_limit`, `ownership_changed`, `duplicate`,
   `handoff`, and `delivery_unknown`.
5. Keep detached work out of the webhook path; the current Redis worker already owns background
   execution. Any future detached task must surface its exception. Make sure worker exceptions are
   retried/dead-lettered with observable error categories.
6. Document the current single-worker limitation and do not scale the AI worker horizontally until
   distributed idempotency is designed and approved.

Acceptance:

- Ownership changes prevent a public reply.
- Duplicate webhook delivery produces at most one confirmed public reply in deterministic tests.
- A message-send timeout is not blindly replayed.
- Total work respects the deadline and worker retry policy.
- Logs contain only IDs, counts, statuses, durations, and reason categories.

## Required test matrix

### Unit tests

- handoff false positives and explicit phrases;
- text normalization and knowledge query extraction;
- message history filtering, role mapping, chronology, truncation, and duplicate-current-message;
- malformed/oversized state, merge/reset/TTL behavior, and ordinal resolution;
- list versus object Management API response parsing.

### Integration tests

- two-turn catalog filter merge;
- exact item detail for “ตัวแรก”;
- knowledge query for payment information without handoff;
- human takeover between LLM work and send;
- duplicate webhook delivery;
- Management/Chatwoot/LLM timeout and retry boundary;
- ambiguous `POST /messages` failure marked `delivery_unknown` without a second public send.

### Quality evaluation

After Slice 3, stop and run at least 10 redacted conversations before continuing. At the end, compare
20 redacted fixtures before/after using the three scores: directness, grounding, and human-like tone.
Any invented business fact is a blocker even if the tone score improves.

Permitted repository checks, after implementation approval:

```text
services/ai: pytest; python3 -m compileall -q src tests
apps/management: php artisan test; npm run typecheck; npm run lint; npm run build
root: docker compose config
```

## Documentation synchronization

When behavior or configuration changes, update the same change set in:

- `SPEC.md` for acceptance criteria, state semantics, retry limits, and TTL;
- `README.md` for setup/configuration and the single-worker limitation;
- `docs/architecture/overview.md` for history retrieval, custom-attribute state, queue/worker flow, and
  delivery uncertainty;
- `services/ai/README.md` for API and runtime behavior.

This documentation requirement supersedes the proposed six-file cap. The cap applies to source
changes, not to the canonical docs that must stay synchronized.

## Open decisions for product-owner approval

1. Context TTL: recommended 24 hours, or a shorter business-specific window.
2. Negotiation behavior: resolved for this slice — ordinary negotiation questions remain AI-owned;
   complaints, payment/refund problems, and explicit human requests are handed to the shared team.
3. Zero-result response: recommended honest cannot-confirm plus one clarification or explicit nearby
   alternative, never arbitrary top-row context.
4. Production rollout: use redacted fixtures and a controlled test inbox before enabling real channel
   traffic. No deployment, secret change, or VM mutation is part of this plan.

## Definition of done

- The revised acceptance conversation passes with exact Management queries and catalog IDs.
- Handoff, ownership, state merge, retry, and privacy tests pass.
- 20-fixture evaluation shows improved directness and tone with no grounding regressions.
- Docs match the checked-out implementation and clearly distinguish verified local behavior from
  unverified production behavior.
- No new infrastructure, dependency, database, or public webhook path is introduced.

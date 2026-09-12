# Reliability fixes — FR-REL-001–004

## Behavior and limits

### Chatwoot contract and human ownership

- Target contract: pinned Chatwoot `v4.16.2-ce`. Conversation custom attributes **replace the full
  map**; `merge=true` is not supported. The client now reads the latest map before merging changes.
- Status transitions use `POST /conversations/{id}/toggle_status` with `status=open`, not PATCH.
- Handoff persists `ai_mode=human` and `ai_handoff_pending=true`, verifies the lock, opens and routes
  to the configured team, then refetches to verify routing before any acknowledgement.
- An interrupted route remains locked and is retried, including when the conversation already
  has human mode. Once routing is verified, pending is cleared **before** acknowledgement; later
  customer events cannot restart a possibly delivered acknowledgement. A crash in that gap may
  omit the acknowledgement, but leaves the conversation routed to the team.
- Ordinary replies never explicitly set `ai_mode=ai`. State writes and each customer-visible POST
  have a fresh eligibility check, including after fetching a Flex card. The human-handling label
  also prevents AI replies. Only the explicit return-to-AI action restores ownership.

**Remaining upstream limitation:** read/merge/write is not a transaction. Chatwoot provides no
compare-and-swap on this endpoint; a human/integration update between GET and POST can still be
overwritten, and takeover between the final GET and message POST can still race. A single worker
does not lock the Chatwoot UI. Strict atomic ownership needs a separately approved server-side
Chatwoot conditional-write/send contract. Do not advertise 100% race-free production behavior.

Contract source: [pinned Chatwoot controller](https://github.com/chatwoot/chatwoot/blob/v4.16.2/app/controllers/api/v1/accounts/conversations_controller.rb).

### Existing Redis queue

- Producer remains RPUSH to the existing queue. Worker atomically LMOVE LEFT → RIGHT into
  `:processing`; ACK removes only after completion. Recovery LMOVE RIGHT → LEFT preserves FIFO.
- One 60-second lease, renewed every 10 seconds. Queue operations and outbound reservations are
  token-fenced Lua operations. Renewal failure cancels processing; an in-flight HTTP request
  cannot be recalled, which is why delivery claims precede sends.
- Three retries, put back at the front to preserve ordering. Malformed/exhausted events go to
  the existing dead-letter key (most recent 1,000). Monitor this private queue; it contains original
  webhook data, must not be printed to logs, and needs a business-approved retention policy.
- Message dedup uses account/conversation/message identity; other event shapes use a payload hash.
  Completed-event markers and per-event public/private/Flex claims expire after seven days.
  Replays after that window are not guaranteed deduplicated.
- Before an outbound POST a pending claim is stored. Known success is marked delivered; replay
  skips that operation. Pending/unknown delivery goes to manual review, not automatic resend.
  Known LINE 4xx rejection permits text fallback; timeout or 5xx does not.
- Claims cannot guarantee exactly-once delivery. A crash after reservation but before network
  send can require review even though nothing was sent. A crash after delivery but before marking
  success is likewise ambiguous. Review the actual Chatwoot/LINE delivery before any manual retry.
- Redis must use persistent storage and AOF, no eviction for these keys, and backups. ACK from
  Redis is not proof of disk fsync; `appendfsync everysec` can lose recent writes on host failure.
  No new Redis infrastructure is introduced by this repair.

Pattern source: [Redis LMOVE reliable queue](https://redis.io/docs/latest/commands/lmove/).

### Seeding and runtime secrets

- `SEED_ON_START` defaults false; bootstrap emits `SEED_DEMO_DATA=false`.
- RealEstateDemoSeeder refuses production. In allowed dev/test environments it creates missing
  records only; existing profile, categories, catalog, FAQs and knowledge are preserved.
- New installations must explicitly run the existing one-time `db:seed --force` provisioning step
  with demo disabled, through a private authorized VM session. This step can refresh the configured
  admin and service token; it is not a routine restart command. Existing databases need no seed.
- AI containers no longer receive the whole root `.env`. Only allowlisted AI settings and the
  existing dedicated OpenRouter/Chatwoot runtime files are passed. Keep those files limited to
  their intended keys. Management/MySQL/Cloudflare secrets must not be added to them.
- LINE application logs contain outcome/status/error class only, not recipient or response body.

## Verification and rollout gates

1. CI runs all AI tests, including real isolated Redis FIFO/recovery/old-duplicate/delivery-claim
   tests, and Management tests/typecheck/lint/build. It does not exercise a live Chatwoot server.
2. Before rollout, obtain environment-specific approval and backups. Stop the **old** AI worker
   before starting the new singleton. Do not run old BRPOP and new LMOVE workers together.
3. Keep the queue/processing/lease/delivery keys intact. Wait for the old lease to expire; never
   delete an active lease to force deployment. Confirm only one worker owns it.
4. In a non-production inbox test assignment failure/recovery, human takeover during generation,
   worker termination before ACK, old duplicate after newer message, and unknown POST outcome.
5. Verify no demo/profile mutation across restart; inspect environment **key names only**, not
   secret values. Confirm queue/backlog/dead-letter monitoring and Redis persistence.
6. Rollback is not a blind start of the old worker: stop the new worker, reconcile processing and
   ambiguous-delivery records first. Old code cannot consume the processing list or honor claims.

No database migration is required. No VM deployment or live customer-message test is implied by CI.

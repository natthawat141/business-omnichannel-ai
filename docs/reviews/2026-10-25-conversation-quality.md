# Conversation quality and lead qualification

## Scope and decision

The product owner requested FR-AI-010–014: Thai/English replies that follow the latest
customer language, qualification of missing budget and desired start, immediate sharing
of confirmed matching inventory, and staff follow-up after consent.

This change prepares the AI runtime and evaluation harness. It does not deploy production,
send test messages to customers, alter inventory, or introduce bookings or a separate lead database.
Existing unrelated Management, storage, document-intake and Chatwoot changes are preserved.

## Findings and implementation

- Fixed Thai catalog fallback messages bypassed the model's language instruction. These replies,
  qualification questions and handoff acknowledgements now have Thai and English variants.
  Short substantive questions such as `How much?` switch language; amounts and confirmations
  preserve the established language.
- Substring parsing confused English words with transaction/category names. Word boundaries
  and validated structured planning now complement deterministic extraction. The planner cannot
  infer buy/rent without a transaction signal in the current message.
- A bounded planner extracts intent and explicit filters. The orchestrator owns consent,
  context merging, inventory retrieval, qualification and human ownership. A failed or invalid
  plan falls back to deterministic extraction.
- Chatwoot custom attributes remember requirements. A new independent search can replace
  old location constraints, while follow-ups preserve needs. The bot asks one missing question,
  shares available results promptly, and records a deterministic needs summary on team handoff.
- Listing names, prices, specifications, photos and maps are rendered from returned records,
  capped at three visible options. A model-generated introduction cannot add imaginary offers.
  Area advice uses separately retrieved business knowledge; it does not prove inventory exists.
- History excludes failed deliveries as well as private notes. Existing ownership checks,
  delivery claims and team-based handoff remain in place. A human takeover suppresses the reply.
- The old eval command checked fixture structure. The replacement executes real orchestration
  against synthetic HTTP boundaries and reports behavior checks separately from human review.

## Verification

Tests were added and observed failing before fixes, including the reported language/context
problems, model-invented offers, stale qualification, guessed transaction type and short English questions.

| Check | Observed result |
| --- | --- |
| Full AI pytest suite | 129 passed, 3 skipped |
| Behavioral regression harness | 24 scenarios / 42 customer turns passed |
| Syntax compilation of source and tests | Passed, excluding macOS AppleDouble metadata files |
| `git diff --check` | Passed |
| Local `docker compose config --quiet` | Not run: Docker CLI is unavailable on this host |

The three skipped tests require a local Redis integration service. The existing FastAPI/Starlette
test-client deprecation warning remains. Queue implementation was not changed.

Compilation command on this external macOS volume:

```bash
python -m compileall -q -x '(^|/)\._' src tests
```

The plain compile command encounters null bytes in `._*.py` filesystem metadata; those are
not Python source and are not included in the isolated evaluation bundle.

### Live-model gate

The final isolated run used the configured `openai/gpt-5.6-luna` model: **4/4 scenarios passed**,
covering 9 customer turns and 9 model calls. All seven planner calls returned validated plans;
the other two calls generated grounded knowledge/smalltalk responses. Provider-reported usage
was 6,251 tokens and approximately USD 0.00210 for this final gate.

| Live scenario | Result |
| --- | --- |
| Thai targeted request asks buy/rent before searching | Passed |
| English rental inquiry → budget → desired start → consent → team summary | Passed |
| Greeting preserves the pending qualification state | Passed |
| Area advice uses supplied business knowledge | Passed |

The gate connected only to OpenRouter with synthetic prompts and inventory. Chatwoot and
Management remained simulated; **zero real customer messages were sent**. This gate checks
contracts and behavior, not a human score for naturalness. Temporary evaluation sources were
removed from the VM and container after collecting the metadata report; the running production
API/worker source and configuration were not changed.

## Configuration and release

- Runtime configuration: `AI_CONVERSATION_PLANNER_ENABLED=true`,
  `AI_PLANNER_TIMEOUT_SECONDS=5`; the existing configured OpenRouter model is reused.
- No database migration or new credential is needed for this feature.
- Production deployment and real WhatsApp end-to-end acceptance are pending explicit
  product-owner release approval under `AGENTS.md`. Both AI API and worker must receive
  the same source/configuration, following the singleton-worker reliability runbook.
- Live read-only inspection found catalog records, but the specific airport knowledge query
  returned no records. Airport recommendations require verified Management knowledge;
  this release does not manufacture it.
- The change cannot establish human-level conversation quality from automated assertions alone.
  Staff should evaluate representative Thai and English chats after an approved deployment.

See [behavior, harness and rollout details](../operations/conversation-quality.md) and
the [Chatwoot infrastructure guide](../../infra/chatwoot/README.md).

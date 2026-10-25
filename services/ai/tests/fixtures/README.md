# Conversation eval fixtures

## Executable quality harness (v2, 2026-10-25)

`conversation_eval_v2.json` contains 24 synthetic scenarios / 42 turns. Run
`python tests/eval_runner.py` from `services/ai`; it now executes actual orchestration against
synthetic HTTP boundaries and returns a nonzero exit code for failed behavior.
The old runner's structure-only success message is no longer a quality gate.

`python tests/eval_runner.py --live --max-cases 4` uses the configured OpenRouter model with
synthetic conversations and inventory; Chatwoot and Management remain mocked. It sends no real
customer messages. Reports contain checks and aggregate metadata, never raw model output.
Thai/English spellings of the same supplied location can be explicit accepted aliases in checks.
Neither mode claims a human naturalness score. See
[conversation quality and rollout](../../../../docs/operations/conversation-quality.md).

The sections below describe the older v1 fixtures and their manual review approach.

Required by [docs/archive/conversational-upgrade-spec.md §10](../../../../docs/archive/conversational-upgrade-spec.md):
**"ห้ามแก้ prompt โดยไม่มีตัววัด"** — do not change `SYSTEM_PROMPT`, `ZERO_RESULT_CLARIFICATION`, or the
routing logic in `is_ai_eligible`/`detect_intent`/`_process_locked` without running this fixture set
before and after, so a fix in one place doesn't silently break another.

## What's in `conversation_eval_v1.json`

20 conversation cases. **These are synthetic, not exported from production** — the spec's method calls
for 20 *real* conversations, but at the time this fixture was built the live deployment had only a
handful of real exchanges (see the LINE conversation reviewed earlier this session: "Hi" / "คุณคือใคร" /
"สนใจอสังหา" / handoff). Case `identity_question_mid_conversation` below is modeled directly on that real
exchange; the rest are derived from the acceptance criteria in `SPEC.md` §10 and
`docs/archive/conversational-upgrade-spec.md` §8, covering the same failure modes seen in production
(immediate handoff on "คุณคือใคร", catalog follow-up context, ordinal references, zero-result knowledge
questions).

**Before this fixture is trusted as a real quality gate, swap in actual exported Chatwoot conversations
once enough real traffic accumulates** (Chatwoot conversation export, PII stripped, only message content
kept as SPEC requires). Until then, treat scores from this set as a regression check against known
failure patterns, not a substitute for real user data.

## Fields per case

- `id` — short slug
- `messages` — the customer-visible turns in order (`role: customer` / `role: bot`, bot turns before the
  final customer message are *given* context, not something to score)
- `expected_routing` — the one thing that's mechanically testable without a human: `answer`, `catalog`,
  `clarify`, or `handoff`, plus `handoff_reason` when applicable. These back deterministic pytest
  assertions (see `test_routing_regression.py`) — same idea as the existing `test_handoff.py`, just
  covering the fuller set of routing decisions, not just `handoff_reason()`.
- `notes` — what a human scorer should specifically watch for on this case

## Scoring (human, per docs/archive/conversational-upgrade-spec.md §10)

For each case, after generating the bot's actual final reply against a real or staging environment, score:

| # | Question | Scale |
|---|---|---|
| 1 | ตอบตรงคำถามหรือไม่ | 0/1 |
| 2 | มีข้อมูลที่แต่งขึ้นเองหรือไม่ | 0/1 — **1 here is a blocker, ship nothing until it's 0** |
| 3 | โทนเหมือนคนหรือเหมือนสคริปต์ | 0/1 |

A prompt/routing change is only safe to ship once all 20 cases are re-scored and item 2 is 0 on every
case. Record scores in a copy of this fixture (or a spreadsheet) dated per run — this repo does not
automate the human-judgment scoring itself, only the routing-decision half.

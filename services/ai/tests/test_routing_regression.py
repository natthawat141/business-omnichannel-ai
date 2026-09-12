"""Deterministic half of the conversation_eval_v1.json fixture (see
tests/fixtures/README.md). Only asserts routing decisions that don't require
a live LLM or human judgment -- answer *quality* is scored separately by a
human against the same fixture, per docs/archive/conversational-upgrade-spec.md §10.
"""

import json
from pathlib import Path

import pytest

from ai_service.main import handoff_reason

FIXTURE_PATH = Path(__file__).parent / "fixtures" / "conversation_eval_v1.json"
CASES = json.loads(FIXTURE_PATH.read_text(encoding="utf-8"))["cases"]


def _last_customer_message(case: dict) -> str:
    customer_turns = [m["content"] for m in case["messages"] if m["role"] == "customer"]
    return customer_turns[-1]


# Only handoffs driven by handoff_reason()'s phrase matching belong here.
# "cannot_confirm" handoffs come from the zero-result-streak path in
# _process_locked and are covered by test_catalog_state.py instead.
_PHRASE_MATCHED_REASONS = {"customer_request", "complaint", "payment_problem"}
HANDOFF_CASES = [
    c for c in CASES
    if c["expected_routing"]["action"] == "handoff"
    and c["expected_routing"].get("handoff_reason") in _PHRASE_MATCHED_REASONS
]
NON_HANDOFF_SINGLE_TURN_CASES = [
    c for c in CASES
    if c["expected_routing"]["action"] != "handoff"
    # handoff_reason() only ever sees the current message; multi-turn cases
    # whose earlier bot turns supply context (e.g. ordinal references) aren't
    # a fair test of it in isolation.
    and len(c["messages"]) == 1
]


@pytest.mark.parametrize("case", HANDOFF_CASES, ids=[c["id"] for c in HANDOFF_CASES])
def test_fixture_handoff_cases_trigger_handoff_reason(case: dict) -> None:
    assert handoff_reason(_last_customer_message(case)) == case["expected_routing"]["handoff_reason"]


@pytest.mark.parametrize(
    "case", NON_HANDOFF_SINGLE_TURN_CASES, ids=[c["id"] for c in NON_HANDOFF_SINGLE_TURN_CASES]
)
def test_fixture_non_handoff_cases_do_not_trigger_handoff_reason(case: dict) -> None:
    assert handoff_reason(_last_customer_message(case)) is None

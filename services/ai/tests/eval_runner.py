#!/usr/bin/env python3
"""Execute synthetic conversations; never send a real Chatwoot/WhatsApp message."""

from __future__ import annotations

import argparse
import asyncio
from copy import deepcopy
import json
from pathlib import Path
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src"))

import ai_service.main as main
from conversation_harness import ConversationHarness, ITEM

FIXTURES_FILE = Path(__file__).parent / "fixtures" / "conversation_eval_v2.json"


def subset(expected, actual):
    if isinstance(expected, dict):
        return isinstance(actual, dict) and all(k in actual and subset(v, actual[k]) for k, v in expected.items())
    return expected == actual


def check_turn(harness, checks, before_public, before_search):
    failures = []
    reply = harness.public[-1] if len(harness.public) > before_public else ""
    for term in checks.get("contains", []):
        if term.casefold() not in reply.casefold():
            failures.append("missing_required_content")
    if checks.get("contains_any") and not any(t.casefold() in reply.casefold() for t in checks["contains_any"]):
        failures.append("missing_next_question")
    for term in checks.get("not_contains", []):
        if term.casefold() in reply.casefold():
            failures.append("forbidden_content")
    if checks.get("language"):
        control_text = reply
        for item in harness.items:
            for key in ("name_th", "name_en", "location_text", "primary_image_url", "map_url"):
                if item.get(key):
                    control_text = control_text.replace(str(item[key]), "")
        if not reply or main.response_language(control_text, checks["language"]) != checks["language"]:
            failures.append("reply_language")
    if "public_delta" in checks and len(harness.public) - before_public != checks["public_delta"]:
        failures.append("public_delivery_count")
    if "search_delta" in checks and len(harness.searches) - before_search != checks["search_delta"]:
        failures.append("search_count")
    if "search" in checks and (not harness.searches or not subset(checks["search"], harness.searches[-1])):
        failures.append("catalog_constraints")
    for key in checks.get("absent_search_fields", []):
        if harness.searches and key in harness.searches[-1]:
            failures.append("unexpected_catalog_constraint")
    if "team_id" in checks and harness.team_id != checks["team_id"]:
        failures.append("handoff_team")
    if "mode" in checks and harness.attributes.get("ai_mode", "ai") != checks["mode"]:
        failures.append("ownership_mode")
    if "requirements" in checks:
        req = json.loads(harness.attributes.get("ai_customer_requirements", "{}"))
        if not subset(checks["requirements"], req):
            failures.append("qualification_memory")
    for term in checks.get("private_contains", []):
        if term.casefold() not in "\n".join(harness.private).casefold():
            failures.append("handoff_summary")
    for alternatives in checks.get("private_contains_any", []):
        if not any(term.casefold() in "\n".join(harness.private).casefold() for term in alternatives):
            failures.append("handoff_summary_alias")
    return sorted(set(failures))


async def run_suite(cases, *, live=False):
    results = []
    for case in cases:
        started = time.monotonic()
        inventory = [deepcopy(ITEM)] if case.get("inventory") == "default" else deepcopy(case.get("inventory", []))
        h = ConversationHarness(inventory, case.get("knowledge", []), live=live)
        h.fail_planner = case.get("planner_outage", False) and not live
        h.takeover_after_plan = case.get("human_takeover", False)
        main._BUSINESS_PROFILE_CACHE.update(value=None, fetched_at=0)
        failures = []
        try:
            for index, turn in enumerate(case["turns"]):
                before_public, before_search = len(h.public), len(h.searches)
                await h.turn(turn["customer"], turn.get("plan"), turn.get("answer"))
                failures.extend(f"turn_{index + 1}:{f}" for f in check_turn(h, turn.get("checks", {}), before_public, before_search))
            if live and case.get("require_planner", True) and h.planner_valid == 0:
                failures.append("no_valid_live_plan")
        except Exception as exc:
            failures.append(f"runtime:{type(exc).__name__}")
        results.append({"id": case["id"], "passed": not failures, "failures": failures,
                        "turns": len(case["turns"]), "model_calls": len(h.model_requests),
                        "validated_plans": h.planner_valid, "duration_seconds": round(time.monotonic() - started, 3),
                        "usage_tokens": h.usage_tokens, "reported_model_cost_usd": h.reported_cost})
    passed = sum(r["passed"] for r in results)
    return {"mode": "live-model-synthetic-boundaries" if live else "deterministic-regression",
            "total": len(results), "passed": passed, "failed": len(results) - passed,
            "real_customer_messages_sent": 0, "human_naturalness_scored": False, "cases": results}


def run_checks():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--live", action="store_true", help="Use the configured OpenRouter model with synthetic data only")
    parser.add_argument("--max-cases", type=int)
    parser.add_argument("--case", action="append", default=[], help="Run only these case IDs")
    parser.add_argument("--report", type=Path, help="Write metadata-only JSON results")
    args = parser.parse_args()
    if args.live and not main.Settings.from_env().openrouter_api_key:
        parser.error("Live mode requires OPENROUTER_API_KEY; never pass credentials on the command line")
    cases = json.loads(FIXTURES_FILE.read_text())["cases"]
    if args.case:
        cases = [c for c in cases if c["id"] in args.case]
        if {c["id"] for c in cases} != set(args.case):
            parser.error("Unknown case ID")
    limit = args.max_cases if args.max_cases is not None else (4 if args.live else len(cases))
    if limit < 1 or (args.live and limit > 10):
        parser.error("Use a positive case count; live mode is capped at 10 cases per run")
    report = asyncio.run(run_suite(cases[:limit], live=args.live))
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 1 if report["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(run_checks())

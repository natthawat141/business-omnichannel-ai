"""Behavioral conversations with synthetic inventory and real orchestration."""

import asyncio
import json
from pathlib import Path

import httpx
import pytest

import ai_service.main as main


from conversation_harness import ConversationHarness, ITEM, plan


@pytest.fixture(autouse=True)
def planner_enabled(monkeypatch):
    monkeypatch.setenv("AI_CONVERSATION_PLANNER_ENABLED", "true")
    main._BUSINESS_PROFILE_CACHE.update(value=None, fetched_at=0)


def test_thai_targeted_request_asks_sale_or_rent_before_claiming_no_match():
    h = ConversationHarness()
    reply = asyncio.run(h.turn("มีคอนโดแถวสีลมไหม", plan({"category_slug": "condo", "location": {"text": "สีลม"}})))
    assert "ซื้อหรือเช่า" in reply
    assert "ยังไม่พบ" not in reply
    assert not h.searches


def test_english_zero_inventory_asks_budget_in_english():
    h = ConversationHarness()
    reply = asyncio.run(h.turn("I want to rent a condo in Silom", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}})))
    assert "budget" in reply.lower()
    assert not any("ก" <= c <= "๙" for c in reply)
    assert h.searches[-1]["location"] == {"text": "Silom"}


def test_matching_inventory_is_shared_immediately_with_real_fields_and_links():
    h = ConversationHarness(items=[ITEM])
    reply = asyncio.run(h.turn("Rent a condo in Silom please", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}})))
    assert ITEM["name_en"] in reply
    assert ITEM["code"] in reply
    assert "14,000" in reply
    assert ITEM["primary_image_url"] in reply
    assert "budget" in reply.lower()


def test_qualification_remembers_budget_and_start_then_routes_callback_with_summary():
    h = ConversationHarness(items=[ITEM])
    async def story():
        await h.turn("Rent a condo in Silom", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}}))
        second = await h.turn("15,000 baht", plan({"price": {"max": 15000}}))
        assert "month" in second.lower() or "move" in second.lower()
        assert "budget" not in second.lower()
        third = await h.turn("next month", plan(desired_start="next month"))
        assert "contact" in third.lower()
        await h.turn("Yes, contact me here please", plan())
    asyncio.run(story())
    assert h.attributes["ai_mode"] == "human"
    assert h.team_id == 2
    assert "human-handling" in h.labels
    summary = "\n".join(h.private)
    assert "15,000" in summary or "15000" in summary
    assert "next month" in summary and "Silom" in summary
    assert "SYN-501" in summary or "501" in summary
    assert "team" in h.public[-1].lower()
    assert not any("ก" <= c <= "๙" for c in h.public[-1])


def test_new_english_request_replaces_stale_location_and_switches_reply_language():
    h = ConversationHarness()
    async def story():
        await h.turn("เช่าคอนโดสีลม", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "สีลม"}}))
        return await h.turn("I'm looking to rent near Suvarnabhumi Airport. Any suggestions?", plan(
            {"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Suvarnabhumi"}},
            new_search=True, intent="area_advice", knowledge_query="Suvarnabhumi",
        ))
    reply = asyncio.run(story())
    assert h.searches[-1]["location"] == {"text": "Suvarnabhumi"}
    assert "budget" in reply.lower()
    assert not any("ก" <= c <= "๙" for c in reply)


def test_english_human_request_is_not_delayed_by_qualification():
    h = ConversationHarness()
    reply = asyncio.run(h.turn("Please let me speak to an agent"))
    assert h.team_id == 2
    assert "team" in reply.lower()
    assert not any("ก" <= c <= "๙" for c in reply)


def test_human_takeover_during_planning_prevents_public_reply():
    h = ConversationHarness(items=[ITEM])
    h.takeover_after_plan = True
    asyncio.run(h.turn("Rent a condo in Silom", plan({"category_slug": "condo", "transaction_type": "rent"})))
    assert h.public == []


def test_invalid_planner_output_never_becomes_catalog_sql_or_unknown_filters():
    h = ConversationHarness()
    asyncio.run(h.turn("Rent a condo in Silom", plan({"sql": "DROP TABLE items", "price": {"max": -1}, "category_slug": "condo"})))
    assert all("sql" not in f for f in h.searches)
    assert all(f.get("price", {}).get("max", 0) >= 0 for f in h.searches)
    assert all("DROP TABLE" not in text for text in h.public)


def test_location_parser_does_not_match_in_inside_looking():
    filters = main.catalog_filters("I'm looking to rent a condo with convenient access to the airport")
    assert "location" not in filters


def test_numeric_reply_keeps_previous_english_language():
    assert main.response_language("15000", "en") == "en"
    assert main.response_language("I need to rent near สีลม", "th") == "en"
    assert main.response_language("ขอแถวสีลม", "en") == "th"


@pytest.mark.parametrize("text", ["How much?", "Rent please", "Budget?", "Available?"])
def test_short_substantive_english_question_switches_from_thai(text):
    assert main.response_language(text, "th") == "en"


@pytest.mark.parametrize("text", ["15000 baht", "yes", "Silom"])
def test_short_confirmation_amount_or_place_keeps_established_language(text):
    assert main.response_language(text, "th") == "th"


def test_untrusted_model_cannot_add_a_listing_or_price_to_catalog_presentation():
    h = ConversationHarness(items=[ITEM])
    reply = asyncio.run(h.turn("Rent a condo in Silom", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}}),
                               answer="Ghost Tower is available for 9,999 THB. I have booked it for you."))
    assert "Ghost Tower" not in reply and "9,999" not in reply
    assert "booked" not in reply
    assert ITEM["code"] in reply


def test_explicit_alternative_request_after_declining_callback_requires_no_extra_consent_loop():
    h = ConversationHarness(items=[ITEM])
    async def story():
        await h.turn("Rent a condo in Silom, budget 10,000, next month", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}, "price": {"max": 10000}}, desired_start="next month"))
        assert h.searches[-1]["price"]["max"] == 10000
        return await h.turn("No callback, show alternatives please", plan())
    reply = asyncio.run(story())
    assert h.searches[-1].get("price") is None
    assert h.searches[-1]["category_slug"] == "condo"
    assert h.searches[-1]["transaction_type"] == "rent"
    assert "alternatives" in reply.lower() and ITEM["code"] in reply
    assert h.team_id is None
    assert not json.loads(h.attributes["ai_customer_requirements"])["callback_offer_pending"]


def test_declining_callback_does_not_ask_for_contact_again():
    h = ConversationHarness(items=[ITEM])
    async def story():
        await h.turn("Rent in Silom, 15,000 budget, next month", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}, "price": {"max": 15000}}, desired_start="next month"))
        return await h.turn("No thanks, just show the listings", plan())
    reply = asyncio.run(story())
    assert "contact" not in reply.lower()
    assert h.team_id is None


def test_unknown_budget_answer_moves_to_start_date_instead_of_repeating():
    h = ConversationHarness()
    async def story():
        await h.turn("I want to rent in Silom", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}}))
        return await h.turn("I'm not sure about my budget yet", plan())
    reply = asyncio.run(story())
    assert "budget" not in reply.lower()
    assert "move" in reply.lower() or "month" in reply.lower()


def test_failed_undelivered_replies_are_not_conversation_history():
    from ai_service.history import build_history
    result = build_history([
        {"id": 1, "message_type": "incoming", "content": "Hello", "created_at": 1},
        {"id": 2, "message_type": "outgoing", "content": "A question the customer never received", "status": "failed", "created_at": 2},
    ])
    assert result == [{"role": "user", "content": "Hello"}]


def test_line_catalog_push_keeps_the_qualification_question_in_same_delivery():
    seen = []
    def transport(request):
        seen.append(json.loads(request.content))
        return httpx.Response(200, json={})
    async def story():
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            return await main.line_push_flex(client, "synthetic", "Usynthetic", {"type": "flex", "altText": "Listings", "contents": {}}, follow_up="What monthly budget works for you?")
    assert asyncio.run(story()) is True
    assert len(seen) == 1
    assert seen[0]["messages"][1] == {"type": "text", "text": "What monthly budget works for you?"}


def test_eval_runner_executes_the_bot_and_fails_on_wrong_behavior():
    import eval_runner
    case = {"id": "prove-execution", "inventory": [], "turns": [{
        "customer": "I want to rent a condo", "plan": plan({"category_slug": "condo", "transaction_type": "rent"}),
        "checks": {"contains": ["budget"], "language": "en"},
    }]}
    good = asyncio.run(eval_runner.run_suite([case]))
    assert good["passed"] == 1 and good["failed"] == 0
    case["turns"][0]["checks"]["contains"] = ["THIS_IS_NOT_A_BOT_REPLY"]
    bad = asyncio.run(eval_runner.run_suite([case]))
    assert bad["passed"] == 0 and bad["failed"] == 1


def test_new_search_flag_alone_cannot_silently_drop_customer_filters():
    h = ConversationHarness()
    async def story():
        await h.turn("Rent a condo in Silom, budget 15000", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}, "price": {"max": 15000}}))
        await h.turn("Tell me more", plan(new_search=True))
    asyncio.run(story())
    assert h.searches[-1]["price"] == {"max": 15000}
    assert h.searches[-1]["location"] == {"text": "Silom"}
    assert json.loads(h.attributes["ai_catalog_filters"])["price"] == {"max": 15000}


def test_short_uncertain_budget_answer_is_not_asked_again():
    h = ConversationHarness()
    async def story():
        await h.turn("อยากเช่าคอนโดสีลม", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "สีลม"}}))
        return await h.turn("ยังไม่แน่ใจครับ", plan())
    reply = asyncio.run(story())
    assert "งบ" not in reply and "เดือนไหน" in reply


def test_corrupted_saved_requirements_do_not_break_handoff():
    h = ConversationHarness()
    h.attributes["ai_customer_requirements"] = json.dumps({"requested_filters": "invalid persisted data"})
    asyncio.run(h.turn("Please contact me here"))
    assert h.team_id == 2
    assert h.private


def test_last_item_reference_uses_only_the_listings_actually_shown():
    items = [{**ITEM, "id": 501 + i, "code": f"SYN-{501+i}"} for i in range(5)]
    h = ConversationHarness(items=items)
    async def story():
        first = await h.turn("Rent a condo in Silom", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}}))
        assert "SYN-503" in first and "SYN-505" not in first
        return await h.turn("Tell me about the last one", plan())
    reply = asyncio.run(story())
    assert "SYN-503" in reply and "SYN-505" not in reply


def test_model_cannot_assume_rental_without_a_customer_transaction_signal():
    h = ConversationHarness(items=[ITEM])
    reply = asyncio.run(h.turn("มีคอนโดแถวสีลมไหม", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "สีลม"}})))
    assert "ซื้อหรือเช่า" in reply and not h.searches


def test_currently_and_thailand_are_not_rent_or_land_keywords():
    assert "transaction_type" not in main.catalog_filters("Are condos currently available in Silom?")
    assert "category_slug" not in main.catalog_filters("Do you operate in Thailand?")


def test_catalog_text_remains_bounded_for_whatsapp_even_with_long_source_links():
    url = "https://example.com/" + "a" * 1950
    items = [{**ITEM, "id": 501 + i, "code": f"SYN-{501+i}", "primary_image_url": url, "map_url": url} for i in range(3)]
    h = ConversationHarness(items=items)
    reply = asyncio.run(h.turn("Rent a condo in Silom", plan({"category_slug": "condo", "transaction_type": "rent", "location": {"text": "Silom"}})))
    assert len(reply) <= 4000


def test_eval_alias_check_accepts_either_name_and_rejects_missing_evidence():
    import eval_runner
    case = {"id": "summary-alias", "require_planner": False, "turns": [{
        "customer": "Please contact me here", "checks": {"private_contains_any": [["missing evidence", "also missing"]]},
    }]}
    report = asyncio.run(eval_runner.run_suite([case]))
    assert report["failed"] == 1
    case["turns"][0]["checks"]["private_contains_any"] = [["customer_request", "other reason"]]
    assert asyncio.run(eval_runner.run_suite([case]))["passed"] == 1


@pytest.mark.parametrize("case", json.loads(
    (Path(__file__).parent / "fixtures" / "conversation_eval_v2.json").read_text()
)["cases"], ids=lambda case: case["id"])
def test_behavioral_conversation_scenarios(case):
    import eval_runner
    report = asyncio.run(eval_runner.run_suite([case]))
    assert report["failed"] == 0, report["cases"][0]["failures"]

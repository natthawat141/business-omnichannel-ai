"""Phase 4.2/4.3-equivalent coverage: identity/greeting/business-meta questions
must be answered from BUSINESS_PROFILE, not handed off, and must not disturb
an in-flight catalog conversation's saved filters. See
tests/fixtures/conversation_eval_v1.json for the human-scored quality half.
"""

import asyncio
import json

import httpx

import ai_service.main as main_module
from ai_service.main import (
    ManagementClient,
    Settings,
    cached_business_profile,
    detect_intent,
)


# Every one of these must resolve to "smalltalk", or _process_locked() will
# route them into the knowledge zero-result path and hand off instead of
# answering -- the exact bug this phase exists to fix.
def test_fixture_smalltalk_cases_are_classified_as_smalltalk() -> None:
    assert detect_intent("คุณคือใคร", "knowledge") == "smalltalk"
    assert detect_intent("สวัสดีครับ", None) == "smalltalk"
    assert detect_intent("ขอบคุณครับ", "catalog") == "smalltalk"
    assert detect_intent("เปิดกี่โมงครับ", None) == "smalltalk"


def test_catalog_terms_still_win_over_smalltalk_terms() -> None:
    # "คอนโด" is a catalog term; it must not be swallowed by any overlapping
    # smalltalk phrase.
    assert detect_intent("มีคอนโดบางนาไหม", None) == "catalog"


def _settings() -> Settings:
    return Settings(
        management_base_url="http://management",
        management_token="management-token",
        chatwoot_base_url="http://chatwoot",
        chatwoot_bot_token="chatwoot-token",
        chatwoot_account_id=1,
        chatwoot_team_id=2,
        webhook_token="webhook-token",
        openrouter_api_key="openrouter-key",
        openrouter_model="test-model",
        allowed_inbox_ids=frozenset(),
        redis_url="redis://redis",
        business_profile_cache_ttl_seconds=300,
    )


class _SmalltalkTransport:
    def __init__(self, custom_attributes: dict[str, object] | None = None) -> None:
        self.attributes: dict[str, object] = dict(custom_attributes or {})
        self.public_messages: list[str] = []
        self.requested_paths: list[str] = []
        self.openrouter_requests: list[dict[str, object]] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requested_paths.append(request.url.path)
        if request.url.host == "openrouter.ai":
            body = json.loads(request.content)
            self.openrouter_requests.append(body)
            return httpx.Response(200, json={"choices": [{"message": {"content": "สวัสดีครับ ผมเป็นผู้ช่วย AI ของธุรกิจนี้ครับ"}}]})
        path = request.url.path
        if path.endswith("/conversations/7") and request.method == "GET":
            return httpx.Response(200, json={
                "status": "open",
                "inbox_id": 1,
                "labels": [],
                "meta": {"assignee": {"bot_type": "webhook"}},
                "custom_attributes": self.attributes,
            })
        if path.endswith("/messages") and request.method == "GET":
            return httpx.Response(200, json={"payload": []})
        if path.endswith("/custom_attributes") and request.method == "POST":
            payload = json.loads(request.content)
            self.attributes = dict(payload["custom_attributes"])
            return httpx.Response(200, json={})
        if path.endswith("/messages") and request.method == "POST":
            body = json.loads(request.content)
            if body.get("private") is not True:
                self.public_messages.append(body["content"])
            return httpx.Response(200, json={})
        if path.endswith("/business-profile") and request.method == "GET":
            return httpx.Response(200, json={"data": {"business_name": "Aion3 Realty", "business_hours": "จันทร์-ศุกร์ 9:00-18:00"}})
        return httpx.Response(200, json={"data": []})


def _reset_business_profile_cache() -> None:
    main_module._BUSINESS_PROFILE_CACHE["value"] = None
    main_module._BUSINESS_PROFILE_CACHE["fetched_at"] = 0.0


def test_smalltalk_is_answered_from_business_profile_without_catalog_or_knowledge_lookup() -> None:
    _reset_business_profile_cache()
    transport = _SmalltalkTransport()

    async def scenario() -> None:
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await main_module.process(_settings(), {
                "account": {"id": 1},
                "message": {"id": 1, "content": "คุณคือใคร", "message_type": "incoming", "conversation": {"id": 7}},
            }, client)

    asyncio.run(scenario())

    assert transport.public_messages == ["สวัสดีครับ ผมเป็นผู้ช่วย AI ของธุรกิจนี้ครับ"]
    assert not any(p.endswith(("/faqs", "/knowledge", "/catalog/search")) for p in transport.requested_paths)
    assert any(p.endswith("/business-profile") for p in transport.requested_paths)
    assert "ai_mode" not in transport.attributes  # normal replies never write ownership
    assert "ai_handoff_reason" not in transport.attributes

    prompt_system_messages = [m["content"] for m in transport.openrouter_requests[0]["messages"] if m["role"] == "system"]
    assert any("Aion3 Realty" in m for m in prompt_system_messages), prompt_system_messages


def test_smalltalk_does_not_clobber_saved_catalog_context() -> None:
    _reset_business_profile_cache()
    transport = _SmalltalkTransport(custom_attributes={
        "ai_mode": "ai",
        "ai_last_intent": "catalog",
        "ai_catalog_filters": json.dumps({"category_slug": "condo", "location": {"text": "บางนา"}}),
        "ai_last_catalog_result_ids": json.dumps([101, 102]),
        "ai_context_updated_at": "2026-08-14T00:00:00+00:00",
    })

    async def scenario() -> None:
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await main_module.process(_settings(), {
                "account": {"id": 1},
                "message": {"id": 1, "content": "ขอบคุณครับ", "message_type": "incoming", "conversation": {"id": 7}},
            }, client)

    asyncio.run(scenario())

    # Only the completion marker should have been written -- the catalog
    # filters/result ids from before this smalltalk turn must survive intact.
    assert transport.attributes["ai_last_intent"] == "catalog"
    assert json.loads(transport.attributes["ai_catalog_filters"])["location"]["text"] == "บางนา"
    assert json.loads(transport.attributes["ai_last_catalog_result_ids"]) == [101, 102]


def test_cached_business_profile_reuses_value_within_ttl() -> None:
    _reset_business_profile_cache()
    calls: list[str] = []

    def transport(request: httpx.Request) -> httpx.Response:
        calls.append(request.url.path)
        return httpx.Response(200, json={"data": {"business_name": "Aion3"}})

    async def scenario() -> tuple[dict, dict]:
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            management = ManagementClient(_settings(), client)
            first = await cached_business_profile(management, ttl_seconds=300)
            second = await cached_business_profile(management, ttl_seconds=300)
            return first, second

    first, second = asyncio.run(scenario())

    assert first == second == {"business_name": "Aion3"}
    assert calls == ["/api/v1/business-profile"]  # second call served from cache


def test_cached_business_profile_serves_stale_value_on_upstream_error() -> None:
    _reset_business_profile_cache()
    call_count = {"n": 0}

    def transport(request: httpx.Request) -> httpx.Response:
        call_count["n"] += 1
        if call_count["n"] == 1:
            return httpx.Response(200, json={"data": {"business_name": "Aion3"}})
        return httpx.Response(500, json={})

    async def scenario() -> dict:
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            management = ManagementClient(_settings(), client)
            await cached_business_profile(management, ttl_seconds=0)  # populate, then force-expire below
            main_module._BUSINESS_PROFILE_CACHE["fetched_at"] = 0.0  # simulate TTL elapsed
            return await cached_business_profile(management, ttl_seconds=300)

    result = asyncio.run(scenario())

    assert result == {"business_name": "Aion3"}  # stale value served, not an empty dict

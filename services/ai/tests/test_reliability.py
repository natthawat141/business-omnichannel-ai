import asyncio
import json
import logging
from dataclasses import replace

import httpx
import pytest

from ai_service.main import ChatwootClient, Settings, catalog_filters, is_smalltalk, line_push_flex


def settings():
    return replace(Settings.from_env(), chatwoot_base_url="http://chatwoot", chatwoot_team_id=2)


@pytest.mark.parametrize("text,amount", [("งบ 30,000 บาท", 30000), ("budget 1,250,000.50 thb", 1250000.5)])
def test_grouped_budget(text, amount):
    assert catalog_filters(text)["price"]["max"] == amount


@pytest.mark.parametrize("text", ["shipping policy", "this property", "which location"])
def test_smalltalk_does_not_match_inside_words(text):
    assert not is_smalltalk(text)


def test_custom_attributes_match_chatwoot_replacement_contract():
    attrs = {"external_crm": "keep", "ai_catalog_filters": "saved"}
    def transport(request):
        if request.method == "GET":
            return httpx.Response(200, json={"custom_attributes": dict(attrs)})
        body = json.loads(request.content)
        attrs.clear()
        attrs.update(body["custom_attributes"])
        return httpx.Response(200, json={})
    async def scenario():
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await ChatwootClient(settings(), client).custom_attributes(1, 2, {"ai_completed_message_id": "10"})
    asyncio.run(scenario())


@pytest.mark.parametrize("phase", ["flex", "state"])
def test_no_reply_when_human_takes_over_during_preparation(monkeypatch, phase):
    from ai_service import main
    state = {"status": "open", "custom_attributes": {}, "contact_inbox": {"source_id": "Usynthetic"}}
    sends = []
    async def answer(*args, **kwargs):
        return "synthetic answer"
    async def profile(*args, **kwargs):
        return {}
    monkeypatch.setattr(main, "grounded_answer", answer)
    monkeypatch.setattr(main, "cached_business_profile", profile)
    def transport(request):
        path = request.url.path
        if path.endswith("/conversations/2"):
            return httpx.Response(200, json=state)
        if path.endswith("/messages") and request.method == "GET":
            return httpx.Response(200, json={"payload": []})
        if path.endswith("/catalog/search"):
            return httpx.Response(200, json={"data": [{"id": 1}]})
        if path.endswith("/flex/carousel"):
            state["custom_attributes"]["ai_mode"] = "human"
            return httpx.Response(200, json={"type": "flex"})
        if path.endswith("/custom_attributes"):
            state["custom_attributes"] = {**json.loads(request.content)["custom_attributes"], "ai_mode": "human"}
            return httpx.Response(200, json={})
        if request.method == "POST":
            sends.append(path)
        return httpx.Response(200, json={})
    async def scenario():
        cfg = replace(settings(), line_channel_access_token="synthetic" if phase == "flex" else "")
        event = {"id": 91, "account": {"id": 1}, "conversation": {"id": 2}, "content": "คอนโด", "message_type": "incoming"}
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await main.process(cfg, event, client)
        assert sends == []
        assert state["custom_attributes"]["ai_mode"] == "human"
    asyncio.run(scenario())


def test_pending_handoff_does_not_reopen_resolved_conversation():
    from ai_service.main import handoff
    writes = []
    def transport(request):
        if request.method == "GET":
            return httpx.Response(200, json={"status": "resolved", "custom_attributes": {"ai_mode": "human", "ai_handoff_pending": True}})
        writes.append(request)
        return httpx.Response(200, json={})
    async def scenario():
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await handoff(ChatwootClient(settings(), client), 1, 2, "customer_request")
        assert writes == []
    asyncio.run(scenario())
    assert attrs == {"external_crm": "keep", "ai_catalog_filters": "saved", "ai_completed_message_id": "10"}


def test_set_open_uses_status_endpoint():
    status = "pending"
    def transport(request):
        nonlocal status
        if request.method == "POST" and request.url.path.endswith("/toggle_status"):
            status = json.loads(request.content)["status"]
        return httpx.Response(200, json={})
    async def scenario():
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await ChatwootClient(settings(), client).set_open(1, 2)
    asyncio.run(scenario())
    assert status == "open"


@pytest.mark.parametrize("status", [200, 400])
def test_line_logs_exclude_recipient_and_response(caplog, status):
    recipient = "Uprivate-test-recipient"
    async def scenario():
        async with httpx.AsyncClient(transport=httpx.MockTransport(lambda r: httpx.Response(status, text="private-response-body"))) as client:
            await line_push_flex(client, "synthetic-token", recipient, {})
    with caplog.at_level(logging.INFO):
        asyncio.run(scenario())
    assert recipient not in caplog.text
    assert "private-response-body" not in caplog.text


def test_handoff_resumes_after_routing_failure():
    from ai_service.main import process, UpstreamError
    state = {"status": "pending", "custom_attributes": {}, "labels": [], "team_id": None}
    public = []
    fail = True
    def transport(request):
        nonlocal fail
        path = request.url.path
        if request.method == "GET":
            return httpx.Response(200, json=state)
        body = json.loads(request.content)
        if path.endswith("/custom_attributes"):
            state["custom_attributes"] = body["custom_attributes"]
        elif path.endswith("/toggle_status"):
            state["status"] = body["status"]
        elif path.endswith("/assignments"):
            if fail:
                fail = False
                return httpx.Response(503)
            state["team_id"] = body["team_id"]
        elif path.endswith("/labels"):
            state["labels"] = body["labels"]
        elif path.endswith("/messages") and not body["private"]:
            assert state["status"] == "open" and state["team_id"] == 2
            public.append(body)
        return httpx.Response(200, json={})
    async def scenario():
        event = {"event": "message_created", "id": 90, "account": {"id": 1}, "conversation": {"id": 2}, "content": "ขอคุยกับเจ้าหน้าที่", "message_type": "incoming"}
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            with pytest.raises(UpstreamError):
                await process(settings(), event, client)
            assert not public
            assert state["custom_attributes"]["ai_mode"] == "human"
            await process(settings(), event, client)
            assert len(public) == 1
            assert state["custom_attributes"]["ai_handoff_pending"] is False
    asyncio.run(scenario())


def test_normal_state_write_refuses_human_takeover():
    posts = []
    def transport(request):
        if request.method == "GET":
            return httpx.Response(200, json={"custom_attributes": {"ai_mode": "human"}})
        posts.append(request)
        return httpx.Response(200, json={})
    async def scenario():
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            assert not await ChatwootClient(settings(), client).custom_attributes(1, 2, {"ai_completed_message_id": "1"}, require_ai=True)
            assert not posts
    asyncio.run(scenario())

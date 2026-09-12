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

import asyncio

import httpx

from ai_service.main import (
    HUMAN_HANDLING_LABEL,
    RETURN_TO_AI_LABEL,
    Settings,
    conversation_event,
    process,
)


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
    )


def test_conversation_event_parses_label_and_custom_attributes() -> None:
    payload = {
        "event": "conversation_updated",
        "id": 42,
        "account": {"id": 1},
        "labels": [HUMAN_HANDLING_LABEL, RETURN_TO_AI_LABEL],
        "custom_attributes": {"ai_mode": "human"},
        "changed_attributes": [{"label_list": {"previous_value": [], "current_value": [RETURN_TO_AI_LABEL]}}],
    }

    parsed = conversation_event(payload)

    assert parsed is not None
    account_id, conversation_id, labels, attrs = parsed
    assert (account_id, conversation_id) == (1, 42)
    assert labels == [HUMAN_HANDLING_LABEL, RETURN_TO_AI_LABEL]
    assert attrs["ai_mode"] == "human"


def test_conversation_event_ignores_message_created_shape() -> None:
    payload = {"event": "message_created", "id": 42, "account": {"id": 1}, "content": "hi"}

    assert conversation_event(payload) is None


def test_return_to_ai_clears_human_state_and_notifies() -> None:
    calls: list[tuple[str, str, object]] = []

    def transport(request: httpx.Request) -> httpx.Response:
        calls.append((request.method, request.url.path, request.content))
        if request.method == "GET" and request.url.path.endswith("/conversations/1"):
            return httpx.Response(
                200,
                json={
                    "id": 1,
                    "status": "open",
                    "labels": [HUMAN_HANDLING_LABEL, RETURN_TO_AI_LABEL],
                    "custom_attributes": {"ai_mode": "human", "ai_handoff_reason": "cannot_confirm"},
                },
            )
        return httpx.Response(200, json={})

    async def scenario() -> None:
        payload = {
            "event": "conversation_updated",
            "id": 1,
            "account": {"id": 1},
            "labels": [HUMAN_HANDLING_LABEL, RETURN_TO_AI_LABEL],
            "custom_attributes": {"ai_mode": "human"},
        }
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(_settings(), payload, client)

    asyncio.run(scenario())

    paths_by_method = [(method, path) for method, path, _ in calls]
    assert ("POST", "/api/v1/accounts/1/conversations/1/assignments") in paths_by_method
    assert ("POST", "/api/v1/accounts/1/conversations/1/custom_attributes") in paths_by_method
    assert ("POST", "/api/v1/accounts/1/conversations/1/labels") in paths_by_method
    assert ("POST", "/api/v1/accounts/1/conversations/1/messages") in paths_by_method

    labels_call = next(body for method, path, body in calls if path.endswith("/labels"))
    assert b'"labels":[]' in labels_call or b'"labels": []' in labels_call

    attrs_call = next(body for method, path, body in calls if path.endswith("/custom_attributes"))
    assert b'"ai_mode":"ai"' in attrs_call or b'"ai_mode": "ai"' in attrs_call


def test_return_to_ai_is_noop_when_already_ai_mode() -> None:
    calls: list[tuple[str, str]] = []

    def transport(request: httpx.Request) -> httpx.Response:
        calls.append((request.method, request.url.path))
        if request.method == "GET" and request.url.path.endswith("/conversations/1"):
            return httpx.Response(
                200,
                json={"id": 1, "status": "open", "labels": [], "custom_attributes": {"ai_mode": "ai"}},
            )
        return httpx.Response(200, json={})

    async def scenario() -> None:
        payload = {
            "event": "conversation_updated",
            "id": 1,
            "account": {"id": 1},
            "labels": [RETURN_TO_AI_LABEL],
            "custom_attributes": {"ai_mode": "human"},
        }
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(_settings(), payload, client)

    asyncio.run(scenario())

    # Refetches to verify, then must not write anything: this both prevents
    # acting on a stale payload and breaks the loop created by our own writes.
    assert calls == [("GET", "/api/v1/accounts/1/conversations/1")]


def test_return_to_ai_is_noop_without_the_label_in_fresh_state() -> None:
    calls: list[str] = []

    def transport(request: httpx.Request) -> httpx.Response:
        calls.append(request.url.path)
        if request.method == "GET" and request.url.path.endswith("/conversations/1"):
            return httpx.Response(
                200,
                json={"id": 1, "status": "open", "labels": [HUMAN_HANDLING_LABEL], "custom_attributes": {"ai_mode": "human"}},
            )
        return httpx.Response(200, json={})

    async def scenario() -> None:
        # Payload pre-filter passes (stale copy still has the label), but the
        # live refetch shows it was removed already.
        payload = {
            "event": "conversation_updated",
            "id": 1,
            "account": {"id": 1},
            "labels": [HUMAN_HANDLING_LABEL, RETURN_TO_AI_LABEL],
            "custom_attributes": {"ai_mode": "human"},
        }
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(_settings(), payload, client)

    asyncio.run(scenario())

    assert calls == ["/api/v1/accounts/1/conversations/1"]


def test_conversation_updated_without_return_label_is_ignored_cheaply() -> None:
    calls: list[str] = []

    def transport(request: httpx.Request) -> httpx.Response:
        calls.append(request.url.path)
        return httpx.Response(200, json={})

    async def scenario() -> None:
        payload = {
            "event": "conversation_updated",
            "id": 1,
            "account": {"id": 1},
            "labels": [HUMAN_HANDLING_LABEL],
            "custom_attributes": {"ai_mode": "human"},
        }
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(_settings(), payload, client)

    asyncio.run(scenario())

    # No live fetch at all: rejected on the payload's own labels/mode.
    assert calls == []


def test_handoff_applies_human_handling_label() -> None:
    calls: list[tuple[str, str, object]] = []
    state = {"id": 1, "status": "open", "labels": [], "custom_attributes": {}}

    def transport(request: httpx.Request) -> httpx.Response:
        calls.append((request.method, request.url.path, request.content))
        if request.method == "GET" and request.url.path.endswith("/conversations/1"):
            return httpx.Response(200, json=state)
        if request.method == "POST":
            import json
            body = json.loads(request.content)
            if request.url.path.endswith("/custom_attributes"):
                state["custom_attributes"] = body["custom_attributes"]
            elif request.url.path.endswith("/assignments"):
                state["team_id"] = body["team_id"]
            elif request.url.path.endswith("/labels"):
                state["labels"] = body["labels"]
        return httpx.Response(200, json={})

    async def scenario() -> None:
        payload = {
            "event": "message_created",
            "id": 99,
            "message_type": "incoming",
            "content": "ขอคุยกับเจ้าหน้าที่",
            "account": {"id": 1},
            "conversation": {"id": 1},
        }
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(_settings(), payload, client)

    asyncio.run(scenario())

    labels_call = next(body for method, path, body in calls if path.endswith("/labels"))
    assert HUMAN_HANDLING_LABEL.encode() in labels_call

import asyncio
import json

import httpx

from ai_service.main import (
    ZERO_RESULT_CLARIFICATION,
    apply_resets,
    detect_intent,
    merge_catalog_filters,
    requested_result_index,
    search_query,
    ManagementClient,
    Settings,
    process,
)


def test_merge_preserves_previous_filters() -> None:
    previous = {
        "category_slug": "condo",
        "location": {"text": "บางนา"},
        "price": {"max": 4_000_000},
    }
    result = merge_catalog_filters(previous, {"attributes": {"bedrooms": {"gte": 2}}})

    assert result["location"]["text"] == "บางนา"
    assert result["price"]["max"] == 4_000_000
    assert result["attributes"]["bedrooms"]["gte"] == 2


def test_transaction_type_overwrite_keeps_rest() -> None:
    result = merge_catalog_filters(
        {"category_slug": "condo", "location": {"text": "บางนา"}, "transaction_type": "sale"},
        {"transaction_type": "rent"},
    )

    assert result["transaction_type"] == "rent"
    assert result["location"]["text"] == "บางนา"


def test_reset_and_followup_routing() -> None:
    filters = apply_resets("ไม่จำกัดงบ", {"price": {"max": 4_000_000}, "location": {"text": "บางนา"}})

    assert "price" not in filters
    assert filters["location"]["text"] == "บางนา"
    assert detect_intent("เอา 2 ห้องนอน", "catalog") == "catalog"


def test_search_query_removes_question_fillers() -> None:
    assert search_query("ชำระเงินยังไงครับ") == "ชำระเงิน"


def test_management_knowledge_passes_bounded_search_query() -> None:
    seen: list[dict[str, str]] = []

    def transport(request: httpx.Request) -> httpx.Response:
        if request.method == "GET":
            seen.append(dict(request.url.params))
        return httpx.Response(200, json={"data": [{"title": "การชำระเงิน"}]})

    async def scenario() -> list[dict[str, object]]:
        settings = Settings(
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
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            return await ManagementClient(settings, client).knowledge(search_query("ชำระเงินยังไงครับ"))

    records = asyncio.run(scenario())

    assert records == [{"title": "การชำระเงิน"}, {"title": "การชำระเงิน"}]
    assert seen == [
        {"limit": "5", "q": "ชำระเงิน"},
        {"limit": "5", "q": "ชำระเงิน"},
    ]


def test_management_knowledge_does_not_fallback_to_unrelated_rows() -> None:
    seen: list[dict[str, str]] = []

    def transport(request: httpx.Request) -> httpx.Response:
        seen.append(dict(request.url.params))
        if "q" in request.url.params:
            return httpx.Response(200, json={"data": []})
        return httpx.Response(200, json={"data": [{"title": "ไม่เกี่ยวกับคำถาม"}]})

    async def scenario() -> list[dict[str, object]]:
        settings = Settings(
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
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            return await ManagementClient(settings, client).knowledge("คำค้นที่ไม่มีอยู่จริง")

    records = asyncio.run(scenario())

    assert records == []
    assert seen == [
        {"limit": "5", "q": "คำค้นที่ไม่มีอยู่จริง"},
        {"limit": "5", "q": "คำค้นที่ไม่มีอยู่จริง"},
    ]


def test_management_flex_carousel_posts_exact_item_ids_in_order() -> None:
    seen: list[dict[str, object]] = []

    def transport(request: httpx.Request) -> httpx.Response:
        seen.append(json.loads(request.content))
        return httpx.Response(200, json={"type": "flex", "contents": {"type": "carousel"}})

    async def scenario() -> dict[str, object] | None:
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            return await ManagementClient(_empty_knowledge_settings(), client).flex_carousel([102, 101])

    payload = asyncio.run(scenario())

    assert payload == {"type": "flex", "contents": {"type": "carousel"}}
    assert seen == [{"item_ids": [102, 101]}]


class _EmptyKnowledgeTransport:
    def __init__(self) -> None:
        self.attributes: dict[str, object] = {}
        self.public_messages: list[str] = []
        self.team_id = None

    def __call__(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path
        if request.url.host == "openrouter.ai":
            return httpx.Response(200, json={"choices": [{"message": {"content": "ข้อมูลที่ไม่ควรแต่งขึ้น"}}]})
        if path.endswith("/conversations/7") and request.method == "GET":
            return httpx.Response(200, json={
                "status": "open",
                "inbox_id": 1,
                "meta": {"assignee": {"bot_type": "webhook"}},
                "custom_attributes": self.attributes,
                "team_id": self.team_id,
            })
        if path.endswith("/assignments") and request.method == "POST":
            self.team_id = json.loads(request.content).get("team_id")
            return httpx.Response(200, json={})
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
        if request.method == "GET" and path.endswith(("/faqs", "/knowledge")):
            return httpx.Response(200, json={"data": []})
        return httpx.Response(200, json={"data": []})


def _empty_knowledge_settings() -> Settings:
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


def test_process_asks_one_clarification_before_handoff_on_empty_knowledge() -> None:
    """SPEC FR-AI-003/§5.5: a single empty-context knowledge question gets one
    focused clarification, not an immediate handoff -- the AI stays eligible."""

    async def scenario() -> _EmptyKnowledgeTransport:
        transport = _EmptyKnowledgeTransport()
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(_empty_knowledge_settings(), {
                "account": {"id": 1},
                "message": {
                    "id": 1,
                    "content": "นโยบายที่ไม่มีข้อมูล",
                    "message_type": "incoming",
                    "conversation": {"id": 7},
                },
            }, client)
        return transport

    transport = asyncio.run(scenario())

    assert "ai_mode" not in transport.attributes  # normal replies never write ownership
    assert "ai_handoff_reason" not in transport.attributes
    assert transport.attributes["ai_zero_result_streak"] == 1
    assert transport.public_messages == [ZERO_RESULT_CLARIFICATION]


def test_process_handoffs_on_second_consecutive_empty_knowledge_answer() -> None:
    async def scenario() -> _EmptyKnowledgeTransport:
        transport = _EmptyKnowledgeTransport()
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            for message_id, content in ((1, "นโยบายที่ไม่มีข้อมูล"), (2, "ยังไม่มีข้อมูลอีกครั้ง")):
                await process(_empty_knowledge_settings(), {
                    "account": {"id": 1},
                    "message": {
                        "id": message_id,
                        "content": content,
                        "message_type": "incoming",
                        "conversation": {"id": 7},
                    },
                }, client)
        return transport

    transport = asyncio.run(scenario())

    assert transport.attributes["ai_mode"] == "human"
    assert transport.attributes["ai_handoff_reason"] == "cannot_confirm"
    assert transport.public_messages == [ZERO_RESULT_CLARIFICATION, "รับเรื่องแล้วครับ กำลังส่งต่อให้ทีมเจ้าหน้าที่ดูแลต่อให้"]


def test_ordinal_reference() -> None:
    assert requested_result_index("ตัวแรกกี่ตารางเมตร") == 0
    assert requested_result_index("ตัวที่สองราคาเท่าไหร่") == 1
    assert requested_result_index("มีคอนโดบางนาไหม") is None


class _ConversationFlowTransport:
    def __init__(self) -> None:
        self.attributes: dict[str, object] = {}
        self.search_payloads: list[dict[str, object]] = []
        self.detail_ids: list[int] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path
        if request.url.host == "openrouter.ai":
            return httpx.Response(200, json={"choices": [{"message": {"content": "ข้อมูลตรงตามรายการครับ"}}]})
        if path.endswith("/conversations/7") and request.method == "GET":
            return httpx.Response(200, json={
                "status": "open",
                "inbox_id": 1,
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
            return httpx.Response(200, json={})
        if path.endswith("/catalog/search") and request.method == "POST":
            payload = json.loads(request.content)
            self.search_payloads.append(payload)
            if "bedrooms" in payload.get("attributes", {}):
                return httpx.Response(200, json={"data": [{"id": 102, "name_th": "B"}]})
            return httpx.Response(200, json={"data": [{"id": 101, "name_th": "A"}, {"id": 102, "name_th": "B"}]})
        if "/catalog/" in path and request.method == "GET":
            item_id = int(path.rsplit("/", 1)[1])
            self.detail_ids.append(item_id)
            return httpx.Response(200, json={"data": {"id": item_id, "usable_area_sqm": 45}})
        return httpx.Response(200, json={"data": []})


def test_process_keeps_catalog_context_and_uses_detail_endpoint() -> None:
    async def scenario() -> _ConversationFlowTransport:
        transport = _ConversationFlowTransport()
        settings = Settings(
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
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            events = [
                (1, "มีคอนโดบางนา งบไม่เกิน 4 ล้านไหม"),
                (2, "เอา 2 ห้องนอน"),
                (3, "ตัวแรกกี่ตารางเมตร"),
            ]
            for message_id, content in events:
                await process(settings, {
                    "account": {"id": 1},
                    "message": {
                        "id": message_id,
                        "content": content,
                        "message_type": "incoming",
                        "conversation": {"id": 7},
                    },
                }, client)
        return transport

    transport = asyncio.run(scenario())

    assert transport.search_payloads[0]["category_slug"] == "condo"
    assert transport.search_payloads[0]["location"] == {"text": "บางนา"}
    assert transport.search_payloads[0]["price"] == {"max": 4_000_000.0}
    assert transport.search_payloads[1]["location"] == {"text": "บางนา"}
    assert transport.search_payloads[1]["price"] == {"max": 4_000_000.0}
    assert transport.search_payloads[1]["attributes"] == {"bedrooms": {"gte": 2}}
    assert transport.detail_ids == [102]


class _CatalogRelaxationTransport:
    def __init__(self, relaxed_results: bool = True) -> None:
        self.attributes: dict[str, object] = {}
        self.search_payloads: list[dict[str, object]] = []
        self.public_messages: list[str] = []
        self.relaxed_results = relaxed_results
        self.openrouter_calls = 0

    def __call__(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path
        if request.url.host == "openrouter.ai":
            self.openrouter_calls += 1
            return httpx.Response(200, json={"choices": [{"message": {"content": "มีตัวเลือกอื่นที่พร้อมเสนอครับ"}}]})
        if path.endswith("/conversations/7") and request.method == "GET":
            return httpx.Response(200, json={
                "status": "open",
                "inbox_id": 1,
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
            payload = json.loads(request.content)
            if payload.get("private") is not True:
                self.public_messages.append(payload["content"])
            return httpx.Response(200, json={})
        if path.endswith("/catalog/search") and request.method == "POST":
            payload = json.loads(request.content)
            self.search_payloads.append(payload)
            if "location" in payload or "price" in payload or "attributes" in payload:
                return httpx.Response(200, json={"data": []})
            records = [{"id": 201, "name_th": "ตัวเลือกอื่น"}] if self.relaxed_results else []
            return httpx.Response(200, json={"data": records})
        if path.endswith("/business-profile") and request.method == "GET":
            return httpx.Response(200, json={"data": {}})
        return httpx.Response(200, json={"data": []})


def _catalog_relaxation_settings() -> Settings:
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


def _catalog_event(message_id: int, content: str) -> dict[str, object]:
    return {
        "account": {"id": 1},
        "message": {
            "id": message_id,
            "content": content,
            "message_type": "incoming",
            "conversation": {"id": 7},
        },
    }


def test_zero_exact_catalog_result_asks_permission_before_relaxing_filters() -> None:
    async def scenario() -> _CatalogRelaxationTransport:
        transport = _CatalogRelaxationTransport()
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(
                _catalog_relaxation_settings(),
                _catalog_event(1, "หาคอนโดซื้อแถวทองหล่อไม่เกิน 8 ล้าน"),
                client,
            )
        return transport

    transport = asyncio.run(scenario())

    assert len(transport.search_payloads) == 1
    assert transport.attributes["ai_catalog_relaxation_pending"] is True
    assert transport.public_messages == [
        "ยังไม่พบรายการที่ตรงทุกเงื่อนไขครับ ต้องการดูตัวเลือกอื่นที่ยังคงประเภททรัพย์และรูปแบบซื้อ/เช่าเดิมไหมครับ"
    ]


def test_customer_consent_relaxes_only_detail_filters() -> None:
    async def scenario() -> _CatalogRelaxationTransport:
        transport = _CatalogRelaxationTransport()
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(
                _catalog_relaxation_settings(),
                _catalog_event(1, "หาคอนโดซื้อแถวทองหล่อไม่เกิน 8 ล้าน"),
                client,
            )
            await process(
                _catalog_relaxation_settings(),
                _catalog_event(2, "ได้ครับ ดูตัวเลือกอื่น"),
                client,
            )
        return transport

    transport = asyncio.run(scenario())

    assert len(transport.search_payloads) == 2
    relaxed = transport.search_payloads[1]
    assert relaxed["category_slug"] == "condo"
    assert relaxed["transaction_type"] == "sale"
    assert "location" not in relaxed
    assert "price" not in relaxed
    assert "attributes" not in relaxed
    assert transport.attributes["ai_catalog_relaxation_pending"] is False
    assert transport.public_messages[-1] == "มีตัวเลือกอื่นที่พร้อมเสนอครับ"


def test_empty_relaxed_search_stays_deterministic_and_offers_handoff() -> None:
    async def scenario() -> _CatalogRelaxationTransport:
        transport = _CatalogRelaxationTransport(relaxed_results=False)
        async with httpx.AsyncClient(transport=httpx.MockTransport(transport)) as client:
            await process(
                _catalog_relaxation_settings(),
                _catalog_event(1, "หาคอนโดซื้อแถวทองหล่อไม่เกิน 8 ล้าน"),
                client,
            )
            await process(
                _catalog_relaxation_settings(),
                _catalog_event(2, "ได้ครับ ดูตัวเลือกอื่น"),
                client,
            )
        return transport

    transport = asyncio.run(scenario())

    assert transport.openrouter_calls == 0
    assert transport.attributes["ai_catalog_relaxation_pending"] is False
    assert transport.public_messages[-1] == (
        "ยังไม่พบตัวเลือกอื่นที่พร้อมเสนอครับ "
        "หากต้องการ ผมส่งต่อให้ทีมเจ้าหน้าที่ช่วยค้นหาเพิ่มเติมได้ครับ"
    )

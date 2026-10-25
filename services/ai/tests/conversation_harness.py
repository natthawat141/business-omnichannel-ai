"""Synthetic HTTP boundaries shared by regression tests and the eval CLI."""

from dataclasses import replace
import json

import httpx

import ai_service.main as main
from ai_service.conversation import validate_plan


ITEM = {
    "id": 501, "code": "SYN-501", "name_th": "ห้องตัวอย่างริมสวน",
    "name_en": "Synthetic Garden Residence", "category_slug": "condo",
    "transaction_type": "rent", "availability": "available", "price": 14000,
    "currency": "THB", "location_text": "Silom / สีลม", "bedrooms": 1,
    "usable_area_sqm": 35, "primary_image_url": "https://example.com/synthetic-room.jpg",
    "map_url": "https://example.com/synthetic-map",
}


class ConversationHarness:
    """Fake only HTTP boundaries; run process(), state, routing and ownership."""

    def __init__(self, items=None, knowledge=None, *, live=False):
        self.items = [dict(i) for i in (items or [])]
        self.knowledge = knowledge or []
        self.attributes = {}
        self.history = []
        self.public = []
        self.private = []
        self.searches = []
        self.model_requests = []
        self.status = "pending"
        self.team_id = None
        self.assignee_id = None
        self.labels = []
        self.plan = {}
        self.answer = None
        self.message_id = 0
        self.fail_planner = False
        self.takeover_after_plan = False
        self.live = live
        self.planner_valid = 0
        self.usage_tokens = 0
        self.reported_cost = None

    def transport(self, request):
        path = request.url.path
        body = json.loads(request.content) if request.content else {}
        if request.url.host == "openrouter.ai":
            self.model_requests.append(body)
            if body.get("response_format"):
                if self.fail_planner:
                    return httpx.Response(503, json={"error": "synthetic outage"})
                if self.takeover_after_plan:
                    self.assignee_id = 99
                text = self.plan if isinstance(self.plan, str) else json.dumps(self.plan)
                try:
                    self.planner_valid += int(validate_plan(json.loads(text)) is not None)
                except ValueError:
                    pass
            else:
                lang = "en" if any("REPLY_LANGUAGE=en" in m.get("content", "") for m in body["messages"]) else "th"
                text = self.answer or ("I can help you compare the confirmed information." if lang == "en" else "ช่วยดูข้อมูลที่ยืนยันได้ให้ครับ")
            return httpx.Response(200, json={"choices": [{"message": {"content": text}}]})
        if path.endswith("/conversations/7"):
            return httpx.Response(200, json={
                "id": 7, "status": self.status, "inbox_id": 1,
                "assignee_id": self.assignee_id, "team_id": self.team_id,
                "meta": {"assignee": {"id": self.assignee_id} if self.assignee_id else {"bot_type": "webhook"}},
                "custom_attributes": self.attributes, "labels": self.labels,
            })
        if path.endswith("/messages"):
            if request.method == "GET":
                return httpx.Response(200, json={"payload": self.history})
            target = self.private if body.get("private") else self.public
            target.append(body["content"])
            if not body.get("private"):
                self.history.append({"id": 10000 + len(self.public), "message_type": "outgoing", "content": body["content"], "created_at": len(self.history)})
            return httpx.Response(200, json={"id": 10000 + len(self.public)})
        if path.endswith("/custom_attributes"):
            self.attributes = dict(body["custom_attributes"])
            return httpx.Response(200, json={})
        if path.endswith("/toggle_status"):
            self.status = body["status"]
            return httpx.Response(200, json={})
        if path.endswith("/assignments"):
            self.team_id = body.get("team_id")
            return httpx.Response(200, json={})
        if path.endswith("/labels"):
            self.labels = body["labels"]
            return httpx.Response(200, json={})
        if path.endswith("/business-profile"):
            return httpx.Response(200, json={"data": {"business_name": "Synthetic Business", "services": "Property assistance"}})
        if path.endswith("/catalog/search"):
            self.searches.append(body)
            found = []
            for item in self.items:
                if any(body.get(k) and body[k] != item.get(k) for k in ("category_slug", "transaction_type")):
                    continue
                location = body.get("location", {}).get("text", "").casefold()
                if location and location not in item.get("location_text", "").casefold():
                    continue
                price = item.get("sale_price") or item.get("price", 0)
                if price > body.get("price", {}).get("max", 1e9) or price < body.get("price", {}).get("min", 0):
                    continue
                found.append(item)
            return httpx.Response(200, json={"data": found[:body.get("limit", 10)]})
        if "/catalog/" in path and request.method == "GET":
            item_id = int(path.rsplit("/", 1)[-1])
            return httpx.Response(200, json={"data": next((x for x in self.items if x["id"] == item_id), {})})
        if path.endswith(("/faqs", "/knowledge")):
            return httpx.Response(200, json={"data": self.knowledge if path.endswith("/knowledge") else []})
        return httpx.Response(200, json={"data": []})

    async def turn(self, text, plan=None, answer=None):
        self.message_id += 1
        self.plan = plan or {}
        self.answer = answer
        self.history.append({"id": self.message_id, "message_type": "incoming", "content": text, "created_at": len(self.history)})
        runtime = main.Settings.from_env()
        settings = replace(
            runtime, management_base_url="http://management",
            management_token="synthetic", chatwoot_base_url="http://chatwoot",
            chatwoot_bot_token="synthetic", chatwoot_account_id=1, chatwoot_team_id=2,
            openrouter_api_key=runtime.openrouter_api_key if self.live else "synthetic",
            openrouter_model=runtime.openrouter_model if self.live else "synthetic-model",
            allowed_inbox_ids=frozenset({1}), business_profile_cache_ttl_seconds=0,
            conversation_planner_enabled=True,
        )
        transport = LiveModelBoundary(self) if self.live else httpx.MockTransport(self.transport)
        async with httpx.AsyncClient(transport=transport) as client:
            await main.process(settings, {"account": {"id": 1}, "message": {
                "id": self.message_id, "content": text, "message_type": "incoming", "conversation": {"id": 7},
            }}, client)
        return self.public[-1] if self.public else ""


class LiveModelBoundary(httpx.AsyncBaseTransport):
    """Only OpenRouter can reach the network; Chatwoot/Management stay synthetic."""

    def __init__(self, harness):
        self.harness = harness
        self.network = httpx.AsyncHTTPTransport(retries=0)

    async def handle_async_request(self, request):
        if request.url.host != "openrouter.ai":
            return self.harness.transport(request)
        if request.url.scheme != "https" or request.url.path != "/api/v1/chat/completions":
            raise AssertionError("Unexpected live evaluation destination")
        body = json.loads(request.content)
        self.harness.model_requests.append(body)
        response = await self.network.handle_async_request(request)
        await response.aread()
        if response.is_success:
            try:
                data = response.json()
                usage = data.get("usage", {})
                self.harness.usage_tokens += int(usage.get("total_tokens", 0))
                if isinstance(usage.get("cost"), (int, float)):
                    self.harness.reported_cost = (self.harness.reported_cost or 0) + usage["cost"]
                if body.get("response_format"):
                    content = data["choices"][0]["message"]["content"]
                    self.harness.planner_valid += int(validate_plan(json.loads(content)) is not None)
            except (ValueError, TypeError, KeyError, IndexError):
                pass
        return response

    async def aclose(self):
        await self.network.aclose()


def plan(filters=None, **kwargs):
    return {"intent": "catalog", "filters": filters or {}, "new_search": False,
            "desired_start": None, "knowledge_query": "", **kwargs}

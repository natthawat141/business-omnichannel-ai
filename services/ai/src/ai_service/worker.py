"""Single leased worker; FIFO, recovery, seven-day dedup and explicit ACK."""
from __future__ import annotations

import asyncio
import hashlib
import json
import logging
from collections.abc import Mapping

import httpx
from redis import asyncio as redis_async

from ai_service.main import QUEUE_KEY, DEAD_LETTER_QUEUE_KEY, Settings, process, event_data
from ai_service.reliable_queue import ReliableQueue, DeliveryUncertain, delivery_context

LOG = logging.getLogger("ai_worker")
MAX_RETRIES = 3


def event_identity(event):
    parsed = event_data(event)
    if parsed and event.get("event") != "conversation_updated":
        identity = ":".join(str(v) for v in parsed[:3])
    else:
        identity = json.dumps(event, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(identity.encode()).hexdigest()


async def consume(q, settings, client):
    await q.recover()
    while True:
        raw = await q.claim()
        if raw is None:
            await asyncio.sleep(0.25)
            continue
        identity = hashlib.sha256(raw.encode()).hexdigest()
        payload = None
        attempts = 0
        context_token = None
        try:
            payload = json.loads(raw)
            if not isinstance(payload, Mapping):
                raise ValueError("invalid event")
            attempts = max(0, int(payload.get("_ai_attempts", 0)))
            event = {k: v for k, v in payload.items() if k != "_ai_attempts"}
            identity = event_identity(event)
            if not await q.completed(identity):
                context_token = delivery_context.set((q, identity))
                await process(settings, event, client)
            await q.finish(raw, identity)
        except DeliveryUncertain:
            await q.dead_letter(raw, identity, "delivery_unknown")
            LOG.error("event_requires_review reason=delivery_unknown")
        except Exception as exc:
            if attempts < MAX_RETRIES and isinstance(payload, Mapping):
                replacement = json.dumps({**payload, "_ai_attempts": attempts + 1}, ensure_ascii=False, separators=(",", ":"))
                await q.retry(raw, replacement)
                LOG.warning("event_retry attempt=%d error=%s", attempts + 1, type(exc).__name__)
                await asyncio.sleep(1)
            else:
                await q.dead_letter(raw, identity, "retry_exhausted")
                LOG.error("event_dead_letter error=%s", type(exc).__name__)
        finally:
            if context_token is not None:
                delivery_context.reset(context_token)


async def renew(q):
    while True:
        await asyncio.sleep(10)
        await q.renew()


async def run():
    settings = Settings.from_env()
    if not settings.redis_url:
        raise RuntimeError("AI_QUEUE_REDIS_URL is required")
    redis = redis_async.from_url(settings.redis_url, decode_responses=True, socket_timeout=5, socket_connect_timeout=5)
    q = ReliableQueue(redis, QUEUE_KEY, DEAD_LETTER_QUEUE_KEY)
    tasks = []
    try:
        if not await q.acquire():
            raise RuntimeError("Another worker owns the lease")
        async with httpx.AsyncClient() as client:
            tasks = [asyncio.create_task(consume(q, settings, client)), asyncio.create_task(renew(q))]
            done, _ = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
            for task in done:
                task.result()
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        try:
            await q.release()
        finally:
            await redis.aclose()


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    asyncio.run(run())

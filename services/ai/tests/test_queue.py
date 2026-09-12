"""Real Redis tests; CI supplies an isolated Redis service, never production."""
import asyncio
import os
import uuid

import pytest
from redis import asyncio as redis


def test_fifo_recovery_dedup_and_delivery_claims():
    from ai_service.reliable_queue import ReliableQueue, DeliveryUncertain

    async def scenario():
        client = redis.from_url(os.environ["TEST_REDIS_URL"], decode_responses=True)
        key = "test:queue:" + uuid.uuid4().hex
        q = ReliableQueue(client, key)
        try:
            assert await q.acquire()
            assert not await ReliableQueue(client, key).acquire()
            await client.rpush(key, "first", "second")
            assert await q.claim() == "first"
            await q.release()  # simulate crash before ACK; processing survives
            assert await q.acquire()
            await q.recover()
            assert await q.claim() == "first"
            await q.finish("first", "event-one")
            assert await q.completed("event-one")
            assert await q.claim() == "second"
            await q.retry("second", "second-retry")
            assert await q.claim() == "second-retry"
            assert await q.reserve("event-two", "public")
            with pytest.raises(DeliveryUncertain):
                await q.reserve("event-two", "public")
            await q.delivered("event-two", "public")
            assert not await q.reserve("event-two", "public")
            await q.finish("second-retry", "event-two")
            assert await q.completed("event-one")  # old duplicate after newer event
            await client.set(q.lease, "another-worker", ex=60)
            from redis.exceptions import ResponseError
            with pytest.raises(ResponseError, match="LEASE_LOST"):
                await q.claim()
            with pytest.raises(ResponseError, match="LEASE_LOST"):
                await q.reserve("event-three", "public")
            with pytest.raises(ResponseError, match="LEASE_LOST"):
                await q.finish("stale", "event-three")
        finally:
            await q.release()
            keys = [item async for item in client.scan_iter(key + "*")]
            if keys:
                await client.delete(*keys)
            await client.aclose()

    if not os.getenv("TEST_REDIS_URL"):
        pytest.skip("TEST_REDIS_URL required; supplied in CI")
    asyncio.run(scenario())


def test_worker_replays_old_duplicate_only_once(monkeypatch):
    from ai_service import worker
    from ai_service.reliable_queue import ReliableQueue, DeliveryUncertain

    async def scenario():
        import json
        client = redis.from_url(os.environ["TEST_REDIS_URL"], decode_responses=True)
        key = "test:queue:" + uuid.uuid4().hex
        q = ReliableQueue(client, key)
        seen = []
        async def fake_process(settings, event, http):
            seen.append(event["id"])
            if event["id"] == 3:
                raise DeliveryUncertain("synthetic")
        monkeypatch.setattr(worker, "process", fake_process)
        events = [{"event": "message_created", "account": {"id": 1}, "conversation": {"id": 2}, "id": i, "content": "synthetic", "message_type": "incoming"} for i in (1, 2, 1, 3)]
        task = None
        try:
            assert await q.acquire()
            await client.rpush(key, *(json.dumps(e) for e in events))
            task = asyncio.create_task(worker.consume(q, None, None))
            async with asyncio.timeout(5):
                while not await q.completed(worker.event_identity(events[-1])):
                    await asyncio.sleep(0.01)
            assert seen == [1, 2, 3]
            assert await client.llen(q.processing) == 0
            assert await client.llen(q.dead) == 1
            assert await client.get(q.marker(worker.event_identity(events[-1]))) == "delivery_unknown"
        finally:
            if task:
                task.cancel()
                await asyncio.gather(task, return_exceptions=True)
            await q.release()
            keys = [item async for item in client.scan_iter(key + "*")]
            if keys:
                await client.delete(*keys)
            await client.aclose()
    if not os.getenv("TEST_REDIS_URL"):
        pytest.skip("TEST_REDIS_URL required; supplied in CI")
    asyncio.run(scenario())

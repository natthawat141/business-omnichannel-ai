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
        finally:
            await q.release()
            keys = [item async for item in client.scan_iter(key + "*")]
            if keys:
                await client.delete(*keys)
            await client.aclose()

    if not os.getenv("TEST_REDIS_URL"):
        pytest.skip("TEST_REDIS_URL required; supplied in CI")
    asyncio.run(scenario())

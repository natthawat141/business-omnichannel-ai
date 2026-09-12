"""FIFO and ACK on existing Redis; lease-fenced writes and delivery claims.

Claims prefer manual reconciliation to blindly resending an uncertain POST.
Exactly-once delivery across Redis and an HTTP server is not guaranteed.
"""
from contextvars import ContextVar
import hashlib
import uuid

TTL = 7 * 86400


class DeliveryUncertain(RuntimeError):
    pass


class ReliableQueue:
    def __init__(self, redis, key, dead_key=None):
        self.redis, self.key = redis, key
        self.dead = dead_key or key + ":dead"
        self.processing = key + ":processing"
        self.lease = key + ":lease"
        self.token = uuid.uuid4().hex

    async def acquire(self):
        return bool(await self.redis.set(self.lease, self.token, nx=True, ex=60))

    async def fenced(self, script, keys=(), args=()):
        return await self.redis.eval(
            "if redis.call('GET', KEYS[1]) ~= ARGV[1] then return redis.error_reply('LEASE_LOST') end\n" + script,
            1 + len(keys), self.lease, *keys, self.token, *args,
        )

    async def renew(self):
        await self.fenced("return redis.call('EXPIRE', KEYS[1], 60)")

    async def release(self):
        await self.redis.eval("if redis.call('GET',KEYS[1]) == ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0", 1, self.lease, self.token)

    async def recover(self):
        await self.fenced("while redis.call('LMOVE',KEYS[2],KEYS[3],'RIGHT','LEFT') do end return 1", (self.processing, self.key))

    async def claim(self):
        return await self.fenced("return redis.call('LMOVE',KEYS[2],KEYS[3],'LEFT','RIGHT')", (self.key, self.processing))

    def marker(self, event):
        return self.key + ":done:" + event

    async def completed(self, event):
        return bool(await self.redis.exists(self.marker(event)))

    async def finish(self, raw, event):
        await self.fenced("redis.call('SET',KEYS[3],'1','EX',ARGV[3]); return redis.call('LREM',KEYS[2],1,ARGV[2])", (self.processing, self.marker(event)), (raw, TTL))

    async def retry(self, raw, replacement):
        await self.fenced("redis.call('LPUSH',KEYS[3],ARGV[3]); return redis.call('LREM',KEYS[2],1,ARGV[2])", (self.processing, self.key), (raw, replacement))

    async def dead_letter(self, raw, event, reason):
        await self.fenced("redis.call('LPUSH',KEYS[3],ARGV[2]); redis.call('LTRIM',KEYS[3],0,999); redis.call('SET',KEYS[4],ARGV[3],'EX',ARGV[4]); return redis.call('LREM',KEYS[2],1,ARGV[2])", (self.processing, self.dead, self.marker(event)), (raw, reason, TTL))

    def delivery_key(self, event, operation):
        return self.key + ":delivery:" + event + ":" + hashlib.sha256(operation.encode()).hexdigest()

    async def reserve(self, event, operation):
        value = await self.fenced("local v=redis.call('GET',KEYS[2]); if v then return v end redis.call('SET',KEYS[2],'pending','EX',ARGV[2]); return 'reserved'", (self.delivery_key(event, operation),), (TTL,))
        if value == "pending":
            raise DeliveryUncertain("Previous delivery must be reconciled")
        return value == "reserved"

    async def delivered(self, event, operation):
        await self.fenced("return redis.call('SET',KEYS[2],'delivered','EX',ARGV[2])", (self.delivery_key(event, operation),), (TTL,))

    async def rejected(self, event, operation):
        await self.fenced("return redis.call('DEL',KEYS[2])", (self.delivery_key(event, operation),))


delivery_context = ContextVar("delivery_context", default=None)


async def reserve_delivery(operation):
    context = delivery_context.get()
    return await context[0].reserve(context[1], operation) if context else True


async def mark_delivered(operation):
    context = delivery_context.get()
    if context:
        await context[0].delivered(context[1], operation)


async def mark_rejected(operation):
    context = delivery_context.get()
    if context:
        await context[0].rejected(context[1], operation)

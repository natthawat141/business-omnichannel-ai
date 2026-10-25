# Python AI Service

The service exposes a FastAPI webhook endpoint and a separate Redis-backed worker. The webhook
acknowledges authenticated Chatwoot events only after Redis accepts them; persistence depends on
Redis AOF/backup configuration. The worker then
performs Chatwoot and Management API calls with bounded retries.

โครง FastAPI สำหรับรับ event จาก Chatwoot และประสานงานกับ Management API

ความสามารถใน Version 1:

- `GET /health` ใช้ตรวจ process health
- `POST /webhooks/chatwoot/{secret-path}` ตรวจ token แบบ constant-time และตอบรับ event ที่ถูกต้อง
- ตรวจ ownership/deduplication ก่อนตอบ, ค้น catalog ผ่าน Management API และเรียก OpenRouter
  ด้วย model ที่กำหนดใน environment
- ใช้ประวัติข้อความสาธารณะล่าสุดและ custom attributes ของ Chatwoot เพื่อคุยต่อเนื่องและอ้างอิงรายการเดิม
- ส่ง ID จากผล Catalog Search ชุดเดียวกันไปสร้าง LINE Flex carousel ตามลำดับเดิม โดย Management
  ตรวจ active, published, effective และ available ซ้ำก่อนสร้างการ์ด
- เมื่อค้นหาทรัพย์แบบ exact แล้วไม่พบ จะขออนุญาตก่อนผ่อนเฉพาะทำเล ราคา และคุณสมบัติ โดยคง
  `category_slug` และ `transaction_type`; หากยังไม่พบจะตอบข้อความคงที่และไม่เรียก LLM เพื่อแต่งทางเลือก
- เก็บ context แบบมีอายุใน Chatwoot custom attributes ได้แก่ `ai_catalog_filters`,
  `ai_last_catalog_result_ids` และ `ai_catalog_relaxation_pending` เพื่อรองรับคำถามต่อเนื่องและ consent
- เมื่อ knowledge search แบบเจาะจงไม่พบผลลัพธ์ จะไม่หยิบแถวแรกที่ไม่เกี่ยวข้องมาเป็น context ให้โมเดล
- ส่งต่อ human handoff ไปยัง Chatwoot team เมื่อผู้ใช้ร้องขอ ร้องเรียน มีปัญหาการชำระเงิน หรือยืนยันข้อมูลไม่ได้

AI ไม่เข้าฐานข้อมูลโดยตรงและไม่รับ SQL จาก LLM; Management เป็นเจ้าของ catalog/knowledge
ส่วน Chatwoot เป็นเจ้าของ conversation และทีมรับช่วงต่อ

## Conversation planning and qualification

เมื่อเปิด `AI_CONVERSATION_PLANNER_ENABLED` ตัว service ใช้แผน JSON ที่ตรวจ schema และ
allowlist ก่อนค้นหา ถามเฉพาะความต้องการที่ยังไม่ทราบ ได้แก่ ซื้อ/เช่า งบ และช่วงเริ่มเช่า
จำคำตอบไว้ใน Chatwoot ตาม TTL เดิม และถามความยินยอมก่อนส่งรายละเอียดให้ทีมติดต่อกลับ
คำขอเจ้าหน้าที่ การร้องเรียน และปัญหาการชำระเงินยังมีลำดับความสำคัญก่อนการถามข้อมูล

ข้อความควบคุมรองรับไทย/อังกฤษตามภาษาล่าสุด คำตอบตัวเลขสั้นใช้ภาษาที่คุยอยู่
ผลลัพธ์แสดงสูงสุดสามรายการพร้อมชื่อ/รหัส ราคา ขนาด และลิงก์ที่ API ส่งคืนจริง
ชื่อและราคาประกอบจากข้อมูลโดยตรง ไม่ให้โมเดลสร้างรายการใหม่ในข้อความนำ
การแนะนำทำเล/การเดินทางใช้ FAQ/Knowledge ที่ตรงเรื่อง และไม่สมมติว่ามีห้องว่างจากบทความทำเล

```bash
python -m pytest -q
python tests/eval_runner.py --report /tmp/conversation-eval.json
# ใช้โมเดลที่ตั้งค่าไว้กับข้อมูลจำลอง; ต้องมี OPENROUTER_API_KEY ใน environment ที่ได้รับอนุญาต
python tests/eval_runner.py --live --max-cases 4 --report /tmp/conversation-live-eval.json
```

runner ใหม่รัน `process()` จริงผ่าน synthetic HTTP boundaries และคืน exit code 1 เมื่อ
พฤติกรรมไม่ผ่าน ไม่ส่งข้อความเข้า Chatwoot/WhatsApp จริง และไม่พิมพ์ prompt หรือคำตอบดิบ
ผล deterministic regression ไม่ใช่คะแนนความเป็นธรรมชาติจากมนุษย์
ดู [พฤติกรรม, harness และ rollout](../../docs/operations/conversation-quality.md)

Production Compose ส่ง flag ให้ทั้ง API และ worker ผ่าน environment ร่วมกัน
ใช้ `AI_CONVERSATION_PLANNER_ENABLED=false` เพื่อกลับไป routing แบบเดิมเมื่อจำเป็น
`AI_PLANNER_TIMEOUT_SECONDS` ค่าเริ่มต้น 5 วินาที; planner ที่ล้มเหลวใช้ fallback ที่มีขอบเขต
การตอบกลับยังอยู่ใต้ processing timeout, ownership และ delivery claims เดิม

Run the API and worker together through the root Docker Compose stack. For local development,
set `REDIS_URL` (or `AI_QUEUE_REDIS_URL`) to a private Redis instance before starting the worker.

Run one worker. A renewable Redis lease fences queue mutations and delivery claims. A crashed
worker leaves its event in a processing list; the successor recovers it before new FIFO events.
Completion IDs and outbound delivery claims last seven days. Unknown POST delivery is dead-lettered
for human reconciliation instead of blindly resent. This is not an exactly-once HTTP guarantee.

See [Reliability and rollout](../../docs/operations/reliability.md) for retry/retention details,
Chatwoot's non-atomic attribute limit, startup seeding changes, and required live checks.

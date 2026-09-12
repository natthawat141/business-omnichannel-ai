# Python AI Service

The service exposes a FastAPI webhook endpoint and a separate Redis-backed worker. The webhook
acknowledges authenticated Chatwoot events only after they are durably queued; the worker then
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

Run the API and worker together through the root Docker Compose stack. For local development,
set `REDIS_URL` (or `AI_QUEUE_REDIS_URL`) to a private Redis instance before starting the worker.

The service is designed to run as a single API/worker process pair. The in-process conversation
lock does not coordinate across multiple Uvicorn/Gunicorn workers; do not scale the AI API to
multiple workers until distributed idempotency is introduced.

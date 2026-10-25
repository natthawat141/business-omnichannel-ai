# Conversation quality: qualification, language and grounded recommendations

ขอบเขตที่ product owner ขอเพิ่มวันที่ 25 ตุลาคม 2026: FR-AI-010–014

## พฤติกรรมที่ตั้งใจให้ลูกค้าได้รับ

1. ลูกค้าถามคอนโดแถวใดโดยยังไม่ระบุซื้อ/เช่า: ถามให้ชัดก่อนใช้ราคาหรืออ้างว่าไม่พบรายการ
2. เมื่อระบุความต้องการแล้ว: ค้น API ด้วย filter ที่ตรวจสอบแล้ว และส่งรายการที่มีข้อมูลยืนยันได้ทันที
3. ถ้างบยังไม่ทราบ: ถามงบที่เหมาะกับรูปแบบธุรกรรม เช่น งบเช่าต่อเดือน
4. ถ้าเป็นเช่าและยังไม่บอกช่วงเริ่ม: ถามเดือนที่ต้องการเริ่มเช่า/เข้าอยู่
5. เมื่อข้อมูลพอ: เสนอให้ทีมตรวจรายละเอียดและติดต่อกลับในแชตนี้ แล้วรอความยินยอม
6. หลังยินยอม: ล็อก AI, ส่งงานให้ทีมที่ตั้งค่าไว้ และบันทึกสรุปความต้องการแบบ deterministic
7. ลูกค้าขอคน ร้องเรียน หรือมีปัญหาการจ่ายเงิน: ส่งต่อทันที ไม่บังคับตอบแบบสอบถามให้ครบก่อน

ตัวอย่างต่อไปนี้เป็นตัวอย่างพฤติกรรมที่ออกแบบ ไม่ใช่ข้อความจริงของลูกค้าหรือผลคะแนนจากมนุษย์:

```text
ลูกค้า: มีคอนโดแถวสีลมไหม
บอต: สนใจซื้อหรือเช่าครับ จะได้คัดรายการและราคาให้ตรงความต้องการ

Customer: I'd like to rent a condo in Silom.
Assistant: [Confirmed matching listings, if any]
           What monthly rental budget would you be comfortable with?
Customer: 15,000 baht.
Assistant: [Updated confirmed options]
           When would you like to move in or start the rental?
Customer: Next month.
Assistant: Would you like our team to review these requirements and contact you here?
Customer: Yes, please.
Assistant: [Acknowledges only after team routing succeeds]
```

## ภาษาและบริบท

- ใช้ภาษาของข้อความลูกค้าล่าสุดที่มีสาระ หรือคำขอให้เปลี่ยนภาษาอย่างชัดเจน
- ตัวเลข คำตอบงบ และคำตอบสั้นไม่เปลี่ยนภาษาโดยไม่มีเหตุผล
- ข้อความคงที่ เช่น ถามข้อมูล ไม่มีผลลัพธ์ และส่งต่อ มีฉบับไทย/อังกฤษ
- ชื่อเฉพาะจากข้อมูลธุรกิจอาจคงรูปเดิม หากยังไม่มีชื่ออีกภาษา
- การเปลี่ยนความต้องการใหม่ล้างเงื่อนไขเก่าที่ไม่ควรติดไป เช่น สีลม → ต้องการเดินทางไปสนามบิน
- การเปลี่ยน preference หนึ่งรายการในบทสนทนาเดิมยังเก็บความต้องการอื่นที่ไม่ได้เปลี่ยน
- ประวัติที่ให้โมเดลยังมีขอบเขตเดิม และตัด private notes กับคำตอบที่ส่งล้มเหลวออก

ข้อมูลที่บันทึกใน Chatwoot:

| Attribute | ความหมาย |
| --- | --- |
| `ai_response_language` | ภาษาที่ใช้ตอบในบทสนทนา |
| `ai_catalog_filters` | filter ของการค้นหาล่าสุด |
| `ai_last_catalog_result_ids` | ID ตามลำดับรายการที่เสนอจริง สูงสุดสามรายการในโหมดใหม่ |
| `ai_customer_requirements` | ช่วงเริ่มที่ลูกค้าบอก, ข้อที่ถามล่าสุด, callback consent state และ filter ความต้องการต้นฉบับ |
| `ai_context_updated_at` | เวลาใช้ตรวจ TTL ของบริบท |

ความต้องการเริ่มเดือนใดเป็นเพียง preference ไม่ใช่นัดหมาย การจอง หรือหลักฐานว่าจะมีห้องว่างเดือนนั้น
ข้อมูลยังอยู่ใน Chatwoot ซึ่งเป็นเจ้าของบทสนทนา ไม่มีตาราง lead อีกชุดใน Management

## การวางแผนและข้อมูลที่ใช้แนะนำ

planner มีหน้าที่คืน intent และ filter แบบ JSON เท่านั้น ใช้ `openai/gpt-6-luna` จาก `OPENROUTER_MODEL` ตามที่ product owner ยืนยันวันที่ 25 ตุลาคม 2026
โค้ดตรวจชนิดข้อมูล ชื่อ field, operator, ช่วงตัวเลข และความยาวก่อนเรียก Management API
คำขอ SQL/field ที่ไม่อยู่ในสัญญาถูกปฏิเสธ และการค้นหายังใช้ API allowlist ของ Management
คำตอบซื้อ/เช่าต้องมีสัญญาณจากข้อความลูกค้า ไม่รับการเดาของ planner มาเป็นความต้องการทันที

ผล inventory แสดงชื่อ/รหัส ทำเล ราคา ขนาด รูป และแผนที่จาก API โดยตรง สูงสุดสามรายการ
URL ต้องเป็น HTTPS ตามข้อจำกัดของ renderer และตัดลิงก์ที่ยาวเกินขอบเขตเพื่อรักษาขนาดข้อความ
คำว่า “ตัวแรก/ตัวสุดท้าย” อ้างจากชุดที่ลูกค้าได้รับจริง
บน LINE คำถาม qualification ส่งเป็นข้อความร่วมกับ Flex ใน HTTP delivery เดียวกัน

คำแนะนำทำเลใช้ความรู้ที่ค้นได้จาก Management FAQ/Knowledge แยกจาก inventory
การมีบทความเกี่ยวกับทำเลไม่ได้ยืนยันว่ามีรายการพร้อมเสนอในทำเลนั้น
หากไม่มีข้อมูลการเดินทาง จะไม่แต่งระยะเวลา สถานี หรือรายการว่าง
สามารถเพิ่ม FAQ/Knowledge ที่ตรวจแหล่งข้อมูลแล้วใน Management เพื่อให้แนะนำได้ครอบคลุมขึ้น

เมื่อผล exact ไม่ตรง ยังไม่ขยายเงื่อนไขเอง ลูกค้าต้องยินยอมหรือขอดู alternatives อย่างชัดเจน
การส่งงานให้ทีมไม่ใช่การรับประกันว่าจะโทรกลับเมื่อใด เว้นแต่มีนโยบายธุรกิจที่ยืนยันเวลานั้น

## Harness ที่รันได้

จาก `services/ai` ใน Python environment ที่ติดตั้ง dependencies แล้ว:

```bash
python -m pytest -q
python tests/eval_runner.py --report /tmp/conversation-eval.json
```

ไฟล์ `tests/fixtures/conversation_eval_v2.json` มีข้อมูลจำลอง 24 scenarios / 42 customer turns
runner เรียก `process()` จริง ไม่ใช่อ่าน expected action แล้วถือว่าผ่าน
HTTP boundaries ของ Chatwoot, Management และโมเดลถูกจำลองในโหมด regression

ตรวจอย่างน้อย:

- ภาษาไทย/อังกฤษและการเปลี่ยนภาษากลางบทสนทนา
- ถามซื้อ/เช่า งบ และช่วงเริ่ม โดยไม่ถามข้อมูลเดิมซ้ำ
- ส่งรายการที่มีอยู่พร้อมค่าจากแหล่งข้อมูล
- context ใหม่ไม่ใช้ทำเลเก่า และ smalltalk ไม่ล้างความต้องการค้าง
- ส่งต่อหลังยินยอม, ปฏิเสธ callback ได้, ขอคน/ร้องเรียน/คืนเงินมี priority
- สรุปงบ ช่วงเริ่ม ทำเล และรายการอ้างอิงให้เจ้าหน้าที่
- exact/relaxed search และ original preferences หลังดู alternatives
- invalid JSON/filter, planner outage, unsafe link และ human takeover

มี regression tests เพิ่มเติมสำหรับการเดาธุรกรรม, substring เช่น `currently`/`Thailand`,
ประวัติข้อความส่งล้มเหลว, ordinal reference และข้อความเกินขนาด

ผล runner มี case ID, pass/fail, check ที่ผิด, จำนวนเทิร์น/model calls, validated plans,
เวลา และ usage/cost ที่ provider รายงาน ไม่เก็บ prompt, raw model replies หรือข้อความลูกค้าจริง
exit code 1 หมายถึงยังมีข้อทดสอบไม่ผ่าน

### Live-model evaluation โดยไม่ส่งข้อความหาลูกค้า

ตั้ง `OPENROUTER_API_KEY` และ `OPENROUTER_MODEL` ผ่าน environment ที่ได้รับอนุญาตแล้วรัน:

```bash
python tests/eval_runner.py --live --max-cases 4 --report /tmp/conversation-live-eval.json

python tests/eval_runner.py --live --max-cases 2 \
  --case th-targeted-transaction \
  --case en-qualify-and-callback
```

เฉพาะ OpenRouter boundary เชื่อม network จริง ส่วน Chatwoot/Management ยังใช้ข้อมูลจำลอง
จึงมีค่าใช้โมเดลแต่ไม่มีการส่ง WhatsApp หรือเขียนบทสนทนาจริง
live mode เริ่มต้นสี่เคส และจำกัดสูงสุดสิบเคสต่อ run ต้องเลือกเคส/รอบทดสอบอย่างมีขอบเขต
case ที่ต้องใช้ planner จะไม่ผ่านหากไม่มีแผนจากโมเดลจริงที่ตรวจ schema ผ่านเลย

ผลผ่านเหล่านี้เป็น regression/contract checks ไม่ใช่การยืนยันว่า “คุยเหมือนมนุษย์” ทุกสถานการณ์
ควรทดลองบทสนทนาที่หลากหลายโดยผู้ดูแล และทบทวนกรณีไม่เข้าใจผ่านข้อมูลที่ได้รับอนุญาต
fixtures v1 ยังเก็บไว้สำหรับ regression เดิม และไม่เรียกการตรวจโครงสร้างว่าเป็น quality score

## Configuration และ rollout

| Environment | ค่าเริ่มต้นใน runtime | หน้าที่ |
| --- | --- | --- |
| `AI_CONVERSATION_PLANNER_ENABLED` | `true` | เปิด conversational planning/qualification |
| `AI_PLANNER_TIMEOUT_SECONDS` | `5` | จำกัดเวลา planner request |
| `OPENROUTER_MODEL` | `openai/gpt-6-luna` | ใช้ทั้ง planner และ grounded response |
| `AI_CONTEXT_TTL_SECONDS` | `86400` | อายุ context ตามสัญญาเดิม |

`Settings.from_env()` เปิดโหมดใหม่ตาม flag; การสร้าง Settings โดยตรงยัง default เป็น legacy
เพื่อความเข้ากันได้ของผู้เรียก/test เดิม Harness ระบุโหมดใหม่อย่างชัดเจน
planner ล้มเหลว/ส่ง schema ผิดจะใช้ deterministic extraction และ qualification ที่จำกัดขอบเขต
ยังรักษา ownership checks ก่อนข้อความสาธารณะ, lease และ delivery claims เดิม

ก่อนปล่อยจริง:

1. รัน regression suite, behavioral harness และ live-model gate ที่เกี่ยวข้อง
2. ตรวจ diff และ source bundle ของ AI ให้ไม่มี credential หรือไฟล์ส่วนอื่นที่ไม่ใช่ release นี้
3. ขออนุมัติ production deployment ตาม AGENTS.md สำหรับ release ที่จะปล่อย
4. บันทึก image/revision และ configuration ปัจจุบันสำหรับ rollback
5. สร้าง image ของ AI แล้วอัปเดต `ai` และ `ai-worker` ตาม compose/project ของ deployment
6. ทำตาม [reliability runbook](reliability.md) สำหรับ singleton worker และรอ lease ของตัวเก่า
7. ตรวจ health, worker และ allowlist แล้วทดสอบแชตกับเบอร์ที่ควบคุมได้
8. หากต้อง rollback ให้คืน image เดิม หรือปิด planner flag แล้ว recreate สอง service ตาม runbook

## แหล่งอ้างอิงและ source map

- [OpenRouter: chat completion และ response format](https://openrouter.ai/docs/api/api-reference/chat/send-chat-completion-request)
- [SPEC.md: FR-AI-010–014](../../SPEC.md)
- [Planner, language และ presentation policy](../../services/ai/src/ai_service/conversation.py)
- [Orchestrator และ ownership](../../services/ai/src/ai_service/main.py)
- [Behavioral eval runner](../../services/ai/tests/eval_runner.py)
- [Synthetic boundaries](../../services/ai/tests/conversation_harness.py)
- [Fixtures v2](../../services/ai/tests/fixtures/conversation_eval_v2.json)
- [Chatwoot infrastructure and inbox configuration](../../infra/chatwoot/README.md)

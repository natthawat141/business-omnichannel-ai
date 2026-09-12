# แผนพัฒนา PDF / Google Drive Document Intake และช่องทาง Agent Access

## สถานะเอกสาร

- สถานะ: **ข้อเสนอเพื่อรอ Product Owner Review**
- วันที่: 2026-09-04
- ขอบเขตของเอกสารนี้: วางแผนเท่านั้น ยังไม่อนุมัติ implementation
- กติกาการดำเนินงาน: ทำทีละ Feature, ตรวจตาม acceptance criteria, สร้าง Review Packet,
  แล้วหยุดรอการอนุมัติก่อนเริ่ม Feature ถัดไป
- ไม่มีการ deploy, push, เปลี่ยน production configuration, ออก credential หรือแก้ข้อมูลจริงในแผนนี้

## 1. เป้าหมาย

ทำให้ Business Admin สามารถนำข้อมูลธุรกิจหรือรายการอสังหาริมทรัพย์เข้าระบบได้โดยไม่ต้องกรอก
ฟอร์มทุกช่องเอง:

1. อัปโหลด PDF จากเครื่อง หรือเลือก PDF จาก Google Drive
2. ระบบอ่านข้อความจาก PDF และรองรับ OCR สำหรับไฟล์สแกนใน Feature แยก
3. AI แปลงข้อมูลเป็น Candidate ที่ตรงกับโครงสร้าง Catalog Item/Knowledge ที่อนุมัติ
4. แสดงข้อมูลที่สกัดได้ พร้อมแหล่งอ้างอิงหน้าเอกสาร ข้อมูลที่ขาด และข้อผิดพลาด
5. Admin ตรวจ แก้ และยืนยันให้สร้างเป็น Draft
6. ข้อมูลจะยังไม่ถูกใช้ตอบลูกค้าจนกว่า Admin จะเปิด Publish ตาม workflow เดิม
7. หลัง API หลักเสถียร จึงค่อยเปิดช่องทาง CLI/MCP ให้ Claude Code, ChatGPT/Codex,
   Hermes หรือ OpenClaw ทำงานผ่านสิทธิ์ที่จำกัดได้

## 2. หลักการสถาปัตยกรรมที่ต้องรักษา

1. Management/MySQL ยังเป็น source of truth ของ Catalog, FAQ, Policy และ Knowledge
2. Chatwoot ยังเป็น source of truth ของ Conversation และ Human Handoff
3. AI หรือ Agent ภายนอกห้ามเข้าถึง MySQL โดยตรง
4. PDF และ Google Drive เป็นเพียงช่องทางนำข้อมูลเข้า ไม่ใช่ source of truth ของข้อมูลที่เผยแพร่แล้ว
5. LLM output ถือเป็นข้อมูลที่ไม่น่าเชื่อถือจนกว่าจะผ่าน schema validation และ human review
6. ไม่มีการเขียนทับ Catalog Item เดิมโดยอัตโนมัติ
7. ไม่มีการ Publish โดย AI, MCP, CLI, Hermes หรือ OpenClaw ใน milestone แรก
8. Catalog facts ที่ค้นหาได้ เช่น ราคา ทำเล ห้องนอน และสถานะ ต้องลง structured fields
   ไม่ใช่เก็บทั้งหมดเป็น Markdown Knowledge
9. ห้ามนำ PDF ทั้งเล่มหรือข้อมูลไม่จำกัดขนาดใส่ prompt
10. ห้าม log เนื้อหา PDF, prompt, raw LLM output, token หรือข้อมูลส่วนบุคคล
11. ทุก migration ต้อง additive และมี rollback ที่ตรวจได้
12. ไม่เพิ่ม queue/Redis, public webhook หรือ runtime service ใหม่โดยไม่ได้รับอนุมัติแยก

## 3. สถานะปัจจุบันที่ตรวจพบ

ระบบที่มีอยู่แล้ว:

- Import รองรับ `.xlsx`, `.xls`, `.csv` ขนาดไม่เกิน 5 MB
- มี Preview ก่อนเขียนฐานข้อมูล
- ข้าม `code` ที่ซ้ำและไม่เขียนทับรายการเดิม
- รายการใหม่ถูกสร้างเป็น `is_published = false`
- มี Import History และ validation สำหรับ structured property fields
- มี Management private storage volume ใน Docker Compose
- มี OpenRouter configuration อยู่ใน `services/ai`
- มีตาราง Laravel jobs จาก framework และ `QUEUE_CONNECTION=database` ในตัวอย่าง config
  แต่ root Compose ยังไม่มี Management queue worker ที่อนุมัติสำหรับ workflow นี้

สิ่งที่ยังไม่มี:

- PDF upload/intake contract
- PDF text extraction และ OCR
- Document source/version/provenance model
- AI extraction schema สำหรับ Catalog Candidate
- Candidate review UI
- Google Drive OAuth/Picker/import
- Drive folder auto-sync
- Document Intake API, CLI หรือ MCP server

ข้อขัดแย้งที่ต้องแก้ก่อน implementation:

- `SPEC.md` ปัจจุบันไม่กำหนด PDF/Drive intake
- `SPEC.md` ปัจจุบันระบุว่า autonomous catalog modification ไม่อยู่ใน Version 1
- ดังนั้น Feature นี้ต้องถูกนิยามว่า AI **สร้าง Candidate/Draft เท่านั้น** และต้องเพิ่ม requirement
  ที่ Product Owner อนุมัติก่อนเริ่มเขียน production code

## 4. ขอบเขต MVP ที่แนะนำ

เพื่อให้ส่งมอบและ Review ได้เร็ว แนะนำให้ milestone แรกมีขอบเขตดังนี้:

- ผู้ใช้: authenticated Business Admin เท่านั้น
- แหล่งข้อมูลแรก: PDF ที่อัปโหลดจากเครื่อง
- PDF ระยะแรก: text-based PDF ก่อน; scanned PDF ส่งสถานะ `ocr_required`
- ขนาดแนะนำ: ไม่เกิน 10 MB
- จำนวนหน้าแนะนำ: ไม่เกิน 20 หน้า
- จำนวน Candidate แนะนำ: ไม่เกิน 20 รายการต่อเอกสาร
- ผลลัพธ์: Catalog Item Candidate เท่านั้นใน milestone แรก
- การเขียนฐานข้อมูลจริง: สร้าง Catalog Item ใหม่เป็น Draft เท่านั้น
- Duplicate: ถ้า `code` ซ้ำ ให้ข้ามและแจ้ง ไม่เขียนทับ
- Publish: Admin ทำจากหน้า Catalog เดิมเท่านั้น
- Processing: synchronous และมี timeout ที่จำกัดใน MVP; ยังไม่เพิ่ม queue worker
- Source file: เก็บใน private storage ที่ไม่เปิด public พร้อม retention policy ที่ต้องอนุมัติ
- Google Drive: นำเข้าแบบกดเลือกไฟล์เองก่อน ยังไม่ sync folder อัตโนมัติ
- MCP/CLI: ทำหลัง workflow ผ่าน UI และ API เสถียรแล้ว

## 5. รูปแบบการทำงานและ Review Gate

ทุก Feature ใช้ขั้นตอนเดียวกัน:

1. ระบุ requirement ID และ acceptance criteria ที่จะทำ
2. ระบุไฟล์ที่จะเปลี่ยนก่อนเริ่มแก้
3. ตรวจ `git status` และแยก user changes ออกจากงาน Feature
4. เขียนหรือปรับ test ให้เห็น failure ที่ต้องแก้ เมื่อเหมาะสม
5. Implement เฉพาะขอบเขต Feature นั้น
6. Run focused tests ก่อน จากนั้น run regression checks ที่เกี่ยวข้อง
7. ตรวจ migration, rollback, security, privacy และ log output
8. อัปเดต canonical docs ใน change set เดียวกัน
9. สร้าง Review Packet ใน `docs/reviews/`
10. หยุดรอ Product Owner/Tech Lead Review
11. เริ่ม Feature ถัดไปได้เมื่อ Review Packet มีคำตัดสิน `APPROVED`

แต่ละ Feature ควรอยู่ใน commit หรือ review unit แยกกัน ห้ามรวม Feature ถัดไปเพื่อแก้ test ของ
Feature ปัจจุบัน และห้าม push/deploy หากไม่ได้รับอนุมัติสำหรับ action นั้นโดยตรง

---

## Feature 0 — Product Contract, Fixtures และ Technical Spike

### เป้าหมาย

กำหนดสัญญาที่ชัดเจนก่อนเขียนระบบจริง และพิสูจน์ว่า PDF parser/LLM path ที่เลือกอ่านข้อความไทย
และคืน structured JSON ได้ในข้อจำกัดที่กำหนด

### งานที่ต้องทำ

1. เพิ่ม proposed requirements ใน `SPEC.md` เช่น:
   - `FR-DOC-001`: Admin อัปโหลด PDF เพื่อสร้าง intake source ได้
   - `FR-DOC-002`: Preview/extraction ต้องไม่เขียน Catalog โดยอัตโนมัติ
   - `FR-DOC-003`: Candidate ทุกตัวต้องมี source document และ page reference
   - `FR-DOC-004`: Candidate ต้องผ่าน allowlisted schema validation
   - `FR-DOC-005`: Confirm สร้าง Draft ใหม่เท่านั้นและไม่ overwrite duplicate code
   - `FR-DOC-006`: Publish ต้องเป็น explicit human action
   - `FR-DOC-007`: Invalid, ambiguous หรือ low-confidence fields ต้องถูกทำเครื่องหมาย
   - `NFR-DOC-001`: จำกัด file size, page count, extracted text และ candidate count
   - `NFR-DOC-002`: ไม่ log document body, prompt หรือ raw model output
2. เพิ่ม acceptance criteria ที่วัดได้สำหรับ digital PDF, scanned PDF, invalid PDF,
   duplicate code, model failure และ human confirmation
3. สร้าง sanitized PDF fixtures อย่างน้อย:
   - text PDF ภาษาไทย 1 รายการทรัพย์
   - text PDF ภาษาไทยหลายรายการ
   - PDF ที่ขาดราคา/ทำเลบางส่วน
   - scanned PDF ที่ไม่มี text layer
   - corrupt/invalid PDF
   - PDF ที่มีข้อความพยายามสั่ง AI เช่น “ignore previous instructions”
4. ห้ามใช้เอกสารลูกค้าจริงหรือ PII ใน fixtures
5. ทำ technical spike เปรียบเทียบ PDF parser อย่างน้อยสองแนวทาง:
   - pure PHP library
   - Poppler/`pdftotext` runtime dependency
6. วัดผล parser ด้วย Thai Unicode, page boundaries, malformed PDF, memory และเวลา
7. ตรวจว่าโมเดล OpenRouter ที่อนุมัติในปัจจุบันรองรับ extraction path ที่ต้องการหรือไม่
   โดยไม่เปลี่ยน provider/model เอง
8. กำหนด strict candidate JSON schema และ prompt versioning contract
9. เขียน ADR ระบุ parser, source retention, model path และเหตุผลที่เลือก

### ไฟล์ที่คาดว่าจะเปลี่ยน

- `SPEC.md`
- `docs/ARCHITECTURE.md`
- `docs/decisions/ADR-00X-document-intake.md` (ใหม่)
- `apps/management/tests/Fixtures/Documents/*` (ใหม่; synthetic only)
- `services/ai/tests/fixtures/documents/*` หาก LLM extraction อยู่ใน AI service

### วิธีตรวจ

- Review requirement ทีละข้อว่าขัดกับ Version 1 boundary หรือไม่
- ตรวจ fixtures ว่าไม่มีชื่อ เบอร์โทร อีเมล ที่อยู่ หรือข้อมูลลูกค้าจริง
- Run parser spike กับ fixture ทุกชนิดและบันทึกเวลา/หน่วยความจำ/จำนวนหน้าที่อ่านได้
- ตรวจ JSON schema ด้วย valid, missing field, extra field, wrong type และ oversized output
- ตรวจ prompt-injection fixture ว่าข้อความใน PDF ถูกปฏิบัติเป็น data ไม่ใช่ instruction

### เกณฑ์ผ่าน

- Product Owner อนุมัติ requirement IDs และ MVP limits
- เลือก parser/model path ได้พร้อมหลักฐาน
- ระบุ dependency และ production packaging impact ได้
- ไม่มี production code ก่อน requirement approval

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-00-document-intake-contract.md`

หลังสร้างเอกสารนี้ให้หยุดรอ Review ก่อนเริ่ม Feature 1

---

## Feature 1 — Secure PDF Upload และ Document Source Lifecycle

### เป้าหมาย

ให้ Admin อัปโหลด PDF ที่ตรวจชนิด/ขนาดแล้วเก็บใน private storage ได้ โดยยังไม่เรียก LLM และยังไม่
สร้าง Catalog Item

### งานที่ต้องทำ

1. เพิ่ม additive migrations สำหรับ `document_sources` โดยมีข้อมูลขั้นต่ำ:
   - owner/admin ID
   - source type (`upload`, อนาคตคือ `google_drive`)
   - original filename ที่ sanitize แล้ว
   - MIME type, byte size, SHA-256
   - private storage path
   - page count เมื่ออ่านได้
   - status (`uploaded`, `extracting`, `ready`, `ocr_required`, `failed`, `cancelled`)
   - failure category แบบ enum; ห้ามเก็บ raw exception ที่อาจมีเนื้อหาเอกสาร
   - timestamps และ retention/expiry timestamp
2. สร้าง model, policy และ controller สำหรับ upload/index/show/cancel
3. ตรวจ extension, MIME และ PDF magic signature; ไม่เชื่อ client filename อย่างเดียว
4. จำกัด file size และ page count ตามค่าที่อนุมัติ
5. ใช้ชื่อไฟล์สุ่มใน private storage; ห้ามวาง PDF ใน public disk
6. ตรวจ duplicate hash และแสดงคำเตือนก่อนสร้าง source ซ้ำ
7. ทำ cleanup เมื่อ upload ล้มเหลวหรือ user กดยกเลิก
8. หน้า UI แสดง filename, size, status และ action ที่อนุญาต
9. เพิ่ม navigation โดยไม่เปลี่ยน workflow Import Excel/CSV เดิม
10. กำหนด retention command แต่ยังไม่ schedule หรือลบจริงจนกว่านโยบายจะอนุมัติ

### ไฟล์ที่คาดว่าจะเพิ่ม/เปลี่ยน

- `apps/management/database/migrations/*_create_document_sources_table.php`
- `apps/management/app/Models/DocumentSource.php`
- `apps/management/app/Policies/DocumentSourcePolicy.php`
- `apps/management/app/Http/Requests/DocumentUploadRequest.php`
- `apps/management/app/Http/Controllers/Admin/DocumentIntakeController.php`
- `apps/management/resources/js/pages/Documents/Index.tsx`
- `apps/management/resources/js/pages/Documents/Show.tsx`
- `apps/management/resources/js/lib/routes.ts`
- `apps/management/resources/js/components/AdminLayout.tsx`
- `apps/management/routes/web.php`
- `apps/management/tests/Feature/DocumentUploadTest.php`
- `apps/management/README.md`
- `apps/management/docs/DOCUMENT_INTAKE.md` (ใหม่)

### วิธีตรวจ

Backend tests:

- unauthenticated user ถูก redirect/ปฏิเสธ
- non-admin ไม่สามารถอัปโหลดหรือเปิด source ของคนอื่น
- valid PDF ถูกเก็บใน private fake storage
- `.pdf` ปลอม, wrong MIME, corrupt PDF, oversized และ too-many-pages ถูกปฏิเสธ
- filename traversal เช่น `../../file.pdf` ไม่กระทบ storage path
- duplicate SHA-256 ไม่ถูกสร้างซ้ำโดยเงียบ
- cancel ลบ private file และเปลี่ยนสถานะตาม contract
- validation error ไม่ทิ้ง orphan file

Frontend checks:

- typecheck, lint และ build ผ่าน
- UI แสดง error ที่เข้าใจง่ายและไม่แสดง server exception
- ไม่มี URL public ที่เปิด PDF โดยไม่ผ่าน authorization

### เกณฑ์ผ่าน

- Upload PDF ได้เฉพาะ Admin
- ไม่มี Catalog/Knowledge row ถูกสร้าง
- ไฟล์ไม่เข้าถึงได้จาก public URL
- invalid upload ไม่ทิ้งไฟล์หรือข้อมูลค้าง
- rollback migration ผ่านในฐานทดสอบ

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-01-secure-pdf-upload.md`

หลังสร้างเอกสารนี้ให้หยุดรอ Review ก่อนเริ่ม Feature 2

---

## Feature 2 — Digital PDF Text Extraction และ Provenance

### เป้าหมาย

อ่านข้อความจาก text-based PDF แบบจำกัดขนาด แยกตามหน้า และเก็บหลักฐานที่จำเป็นต่อการ Review
โดยยังไม่เรียก LLM และยังไม่สร้าง Catalog Candidate

### งานที่ต้องทำ

1. สร้าง `DocumentTextExtractor` interface เพื่อไม่ผูก domain logic กับ parser เจ้าเดียว
2. Implement adapter ที่ผ่าน Feature 0 spike
3. แยก text ตาม page number และ normalize Unicode/whitespace โดยไม่เปลี่ยนตัวเลขหรือหน่วย
4. จำกัด:
   - จำนวนหน้า
   - จำนวนอักขระต่อหน้า
   - จำนวนอักขระรวม
   - processing timeout/memory
5. เก็บ extracted artifact ใน private storage หรือ storage model ที่ ADR อนุมัติ
6. เก็บ hash/version ของ extracted artifact และ parser version
7. ตรวจ low-text document และตั้งสถานะ `ocr_required` แทนการเดาข้อมูล
8. ห้าม log extracted text หรือ PDF content
9. แสดง Preview ทีละหน้าให้ Admin ตรวจว่าระบบอ่านเอกสารได้จริง
10. การ Retry ต้องสร้าง extraction run ใหม่หรือมี idempotency ที่ชัดเจน ไม่ overwrite evidence เดิม

### ไฟล์ที่คาดว่าจะเพิ่ม/เปลี่ยน

- `apps/management/app/Contracts/DocumentTextExtractor.php`
- `apps/management/app/Services/Documents/*PdfTextExtractor.php`
- `apps/management/app/Models/DocumentExtractionRun.php`
- `apps/management/database/migrations/*_create_document_extraction_runs_table.php`
- `apps/management/app/Http/Controllers/Admin/DocumentExtractionController.php`
- `apps/management/resources/js/pages/Documents/Show.tsx`
- `apps/management/routes/web.php`
- `apps/management/tests/Unit/DocumentTextExtractorTest.php`
- `apps/management/tests/Feature/DocumentExtractionTest.php`
- `apps/management/Dockerfile` และ `composer.json` เฉพาะ dependency ที่ได้รับอนุมัติ

### วิธีตรวจ

- เทียบ extracted Thai text กับ expected snippets ของแต่ละหน้า
- ตรวจว่าตัวเลขราคา พื้นที่ และหน่วยไม่ถูกเปลี่ยนระหว่าง normalization
- scanned PDF ต้องได้ `ocr_required`, ไม่ได้ empty-success
- corrupt/encrypted/oversized PDF ต้อง fail ด้วย sanitized reason category
- timeout/retry ไม่สร้าง run ซ้ำโดยไม่จำเป็น
- logs มีเฉพาะ source ID, status, duration, page/character count และ error category
- test ว่า extracted artifact เปิดได้เฉพาะ Admin ที่มีสิทธิ์

### เกณฑ์ผ่าน

- text PDF fixture อ่านได้ครบตาม expected pages
- scanned PDF แยกออกจาก digital PDF ได้อย่างปลอดภัย
- provenance ระบุ source hash, parser version และ page number ได้
- ไม่มี LLM call และไม่มี Catalog write ใน Feature นี้

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-02-pdf-text-extraction.md`

หลังสร้างเอกสารนี้ให้หยุดรอ Review ก่อนเริ่ม Feature 3

---

## Feature 3 — AI Structured Candidate Extraction

### เป้าหมาย

ส่งเฉพาะ bounded page text ไปยัง AI service และรับ Catalog Candidate ที่ตรง strict schema กลับมา
โดยไม่เขียน Catalog และไม่เชื่อ raw model output

### ข้อเสนอการวาง ownership

แนะนำให้ `services/ai` เป็นผู้เรียก OpenRouter เพราะมี provider/model configuration อยู่แล้ว และ
ตรงกับ ownership ของ prompt construction/LLM orchestration ส่วน Management เป็นผู้เก็บ source,
candidate, validation และ human review

ก่อน implement ต้องอนุมัติ internal authenticated endpoint ระหว่าง Management → AI service และยืนยันว่า
ไม่ถือเป็น public channel/webhook path ใหม่

### งานที่ต้องทำ

1. สร้าง internal extraction contract แบบ versioned
2. ใช้ service-to-service token ที่ scoped, revocable และไม่อยู่ใน source control
3. สร้าง strict request schema:
   - source ID แบบ opaque
   - bounded page text พร้อม page number
   - allowed catalog fields/category definitions
   - max candidates
   - schema/prompt version
4. สร้าง strict response schemaสำหรับ Catalog Candidate:
   - allowlisted fields เท่านั้น
   - `null` สำหรับข้อมูลที่เอกสารไม่มี
   - source page references ต่อ field หรือ candidate
   - warning/missing fields
   - ห้าม model สร้าง publish/active decision
5. แยก system instructions ออกจาก document text และระบุว่า document text เป็น untrusted data
6. validate response ทั้งใน AI service และ Management
7. reject extra keys, wrong types, invalid enums, negative price/area, invalid HTTPS URL และ oversized text
8. เก็บ model name, prompt version, schema version, started/completed timestamps และ sanitized status
9. เก็บ candidate payload ที่ผ่าน validation เท่านั้น; raw model output ไม่ลง log
10. หาก AI service unavailable, timeout หรือ invalid output ให้ fail closed และอนุญาต explicit retry
11. ไม่ใช้ automatic model fallback หาก Product Owner ยังไม่อนุมัติ

### ไฟล์ที่คาดว่าจะเพิ่ม/เปลี่ยน

AI service:

- `services/ai/src/ai_service/document_extraction.py`
- `services/ai/src/ai_service/document_schemas.py`
- `services/ai/src/ai_service/main.py` หรือ router แยกตามโครงสร้างที่ Review อนุมัติ
- `services/ai/tests/test_document_extraction.py`
- `services/ai/.env.example`
- `services/ai/README.md`

Management:

- `apps/management/app/Services/Documents/AiDocumentExtractionClient.php`
- `apps/management/app/Models/DocumentCandidate.php`
- `apps/management/database/migrations/*_create_document_candidates_table.php`
- `apps/management/app/Http/Controllers/Admin/DocumentExtractionController.php`
- `apps/management/config/services.php`
- `apps/management/.env.example`
- `apps/management/tests/Feature/AiDocumentExtractionTest.php`

Root docs/config:

- `.env.example`
- `compose.yml` เฉพาะ network/config ที่จำเป็นและอนุมัติ
- `docs/ARCHITECTURE.md`

### วิธีตรวจ

AI service tests:

- valid single/multi-candidate response
- missing field กลับเป็น `null`, ไม่เดาค่า
- prompt injection ใน PDF ไม่เปลี่ยน system policy
- extra keys/wrong enum/wrong type/oversized response ถูก reject
- model timeout, 429, malformed JSON และ unavailable provider fail closed
- ไม่มี document body/raw model output ใน log capture

Management tests:

- HTTP client ส่งเฉพาะ bounded schema
- invalid AI response ไม่สร้าง candidate
- valid response สร้าง pending candidates แต่ไม่สร้าง package
- retry/idempotency ไม่สร้าง candidates ซ้ำ
- service token ไม่ถูกส่งกลับ UI หรือ log

### เกณฑ์ผ่าน

- AI สร้าง Candidate จาก synthetic PDF ได้ตาม schema
- ทุก Candidate trace กลับไปยัง source/page ได้
- ไม่มี Catalog row ถูกสร้างใน Feature นี้
- unavailable/invalid AI ไม่ทำให้ข้อมูลบางส่วนถูกบันทึกเป็น success

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-03-ai-candidate-extraction.md`

หลังสร้างเอกสารนี้ให้หยุดรอ Review ก่อนเริ่ม Feature 4

---

## Feature 4 — Candidate Review, Edit, Confirm และ Draft Creation

### เป้าหมาย

ให้ Admin ตรวจ Candidate เทียบกับหน้าเอกสาร แก้ข้อมูล และยืนยันสร้าง Catalog Item Draft โดยใช้
validation contract เดียวกับหน้า Catalog ปัจจุบัน

### งานที่ต้องทำ

1. ทำหน้า Candidate Review แสดง:
   - source filename และ version/hash
   - extracted fields
   - source page/evidence
   - missing/invalid/ambiguous fields
   - duplicate code warning
2. ให้ Admin แก้ Candidate ก่อนยืนยันได้
3. รวม validation ของ Package form/import/candidate ให้ใช้กฎชุดเดียวกัน เพื่อลด schema drift
4. ห้าม AI-generated field bypass `PackageRequest`/domain validation
5. Confirm ผ่าน explicit modal ที่บอกจำนวนที่จะสร้าง, ข้าม และผิดพลาด
6. ใช้ database transaction สร้างเฉพาะรายการใหม่
7. ตั้งค่าคงที่:
   - `is_published = false`
   - ไม่เปิด publish อัตโนมัติ
   - duplicate `code` ข้าม ไม่ update record เดิม
8. บันทึก reviewer ID, reviewed timestamp และ candidate decision (`accepted`, `rejected`)
9. สร้าง ImportRecord/DocumentImportRecord ที่เชื่อมกลับ source และ candidate ได้
10. เมื่อ confirm สำเร็จ ให้ลิงก์ไปหน้า Catalog Draft เพื่อ review/publish ตาม workflow เดิม
11. Cancel/Reject ต้องไม่สร้าง Catalog row

### ไฟล์ที่คาดว่าจะเพิ่ม/เปลี่ยน

- `apps/management/resources/js/pages/Documents/Review.tsx`
- `apps/management/app/Http/Requests/DocumentCandidateRequest.php`
- `apps/management/app/Http/Controllers/Admin/DocumentCandidateController.php`
- `apps/management/app/Services/Documents/ConfirmDocumentCandidates.php`
- `apps/management/app/Http/Requests/PackageRequest.php` หรือ validator กลางที่ Review อนุมัติ
- `apps/management/app/Models/DocumentCandidate.php`
- `apps/management/app/Models/ImportRecord.php`
- `apps/management/database/migrations/*_add_document_provenance_to_import_records.php`
- `apps/management/routes/web.php`
- `apps/management/tests/Feature/DocumentCandidateReviewTest.php`
- `apps/management/tests/Feature/ImportTest.php`
- `apps/management/docs/DOCUMENT_INTAKE.md`
- `SPEC.md`

### วิธีตรวจ

- Preview/แก้ Candidate ไม่เขียน packages
- Confirm สร้างเฉพาะ valid candidates เป็น Draft
- duplicate code ในฐานข้อมูลหรือใน PDF เดียวกันไม่ overwrite
- invalid category, price, date, URL, area และ enum ไม่ผ่าน
- สอง request confirm พร้อมกันไม่สร้าง code ซ้ำ
- reject/cancel ไม่สร้าง Catalog row
- unpublished item ไม่ปรากฏใน Catalog Search/Flex/AI response
- reviewer/provenance เชื่อมกลับ source และ candidate ได้
- ทดสอบ regression ของ Excel/CSV import เดิมทั้งหมด

### เกณฑ์ผ่าน

- ผู้ใช้ไม่ต้องกรอกฟอร์มตั้งแต่ศูนย์ แต่ตรวจและแก้ AI Draft ได้
- ข้อมูลใหม่ยังไม่ถูก AI ใช้จนกว่า Admin จะ Publish ภายหลัง
- no-overwrite และ backward compatibility ของ Excel/CSV ยังผ่าน
- Catalog Search/Flex ไม่เห็น Draft

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-04-candidate-review-and-draft.md`

นี่คือจุดสิ้นสุด **PDF MVP** หลัง Review ผ่านจึงพิจารณา Feature 5 เป็นต้นไป

---

## Feature 5 — OCR สำหรับ Scanned PDF (แยกอนุมัติ)

### เหตุผลที่แยก Feature

OCR มีผลต่อ dependency, ค่าใช้จ่าย, privacy, latency และคุณภาพภาษาไทย จึงไม่ควรผูกกับ PDF MVP
ก่อนรู้ว่าลูกค้ามี scanned PDF มากเพียงใด

### Decision Gate

เปรียบเทียบอย่างน้อย:

- Local OCR เช่น Tesseract: ค่า API ต่ำ แต่เพิ่ม runtime package และต้องวัดภาษาไทย/ตาราง
- Google Cloud Vision/Document AI: คุณภาพและโครงสร้างอาจดีกว่า แต่มีค่าใช้จ่าย/credential/data transfer
- Approved multimodal model path: ลด component แต่ต้องพิสูจน์ page/provenance/structured accuracy

Product Owner ต้องเลือก provider, budget, data-retention และ region ก่อน implementation

### งานที่ต้องทำหลังอนุมัติ

1. เพิ่ม `OcrDocumentTextExtractor` ภายใต้ interface เดิม
2. จำกัด DPI/pages/bytes/time/cost
3. เก็บ OCR provider/model/version และ page-level provenance
4. scanned PDF ใช้ workflow Candidate เดิม ไม่ bypass human review
5. fail closed เมื่อ confidence ต่ำหรือ provider unavailable

### วิธีตรวจ

- synthetic Thai scanned fixtures หลายคุณภาพ
- ตัวเลขราคาและหน่วยต้องเทียบกับ expected values
- rotated pages, tables, low resolution และ blank pages
- provider timeout/quota/invalid credential
- ตรวจ log/privacy และ cost metadata ที่ไม่เปิดเผยเนื้อหา

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-05-scanned-pdf-ocr.md`

---

## Feature 6 — Google Drive Manual Import

### เป้าหมาย

ให้ Admin กด “เลือกจาก Google Drive” และนำ PDF หนึ่งไฟล์เข้าสู่ Document Intake pipeline เดิม
โดยไม่ต้องดาวน์โหลดแล้วอัปโหลดเอง

### ขอบเขต

- Manual selection/import เท่านั้น
- PDF เท่านั้นในรอบแรก
- ยังไม่มี folder watch หรือ automatic sync
- Drive file ไม่สามารถ Publish หรือแก้ Catalog โดยตรง

### งานที่ต้องทำ

1. เลือกรูปแบบ auth สำหรับ single business:
   - OAuth user connection + Google Picker (แนะนำสำหรับ UX)
   - service account + shared folder (ง่ายด้าน operation แต่ UX จำกัด)
2. สร้าง Google Cloud OAuth configuration โดยเก็บ client secret/refresh token นอก source control
3. OAuth ต้องมี state validation, least-privilege scopes และ token revocation/disconnect
4. Store refresh token แบบ encrypted หรือ secret-store pattern ที่ ADR อนุมัติ
5. Picker จำกัด MIME เป็น PDF
6. Backend ตรวจ file ID, MIME, size, download permission และ Drive metadata ซ้ำ
7. ดาวน์โหลดไฟล์เข้า private storage แล้วเรียก Document Intake path เดียวกับ local upload
8. เก็บ external file ID, revision/version, modified time และ checksum ที่หาได้
9. การ import ไฟล์เดิม/version เดิมซ้ำต้องเตือนหรือ idempotent
10. Drive error, permission revoked, deleted file และ download timeout ต้อง fail closed
11. ห้ามส่ง Google token ไปยัง browser นอก flow ที่จำเป็น หรือเขียนลง log

### ไฟล์ที่คาดว่าจะเพิ่ม/เปลี่ยน

- `apps/management/app/Services/GoogleDrive/*`
- `apps/management/app/Http/Controllers/Admin/GoogleDriveConnectionController.php`
- `apps/management/app/Http/Controllers/Admin/GoogleDriveImportController.php`
- `apps/management/app/Models/ExternalFileConnection.php` หรือ singleton connection model
- `apps/management/database/migrations/*_create_external_file_connections_table.php`
- `apps/management/resources/js/pages/Documents/Index.tsx`
- `apps/management/config/services.php`
- `apps/management/routes/web.php`
- `apps/management/.env.example`
- `apps/management/tests/Feature/GoogleDriveConnectionTest.php`
- `apps/management/tests/Feature/GoogleDriveImportTest.php`
- `apps/management/docs/GOOGLE_DRIVE_INTEGRATION.md`

### วิธีตรวจ

- OAuth state mismatch/replay ถูกปฏิเสธ
- non-admin เริ่ม OAuth/import ไม่ได้
- scope เกินความจำเป็นไม่ถูกขอ
- fake Drive API: valid PDF, non-PDF, oversized, no permission, deleted, timeout
- Drive file version เดิมไม่สร้าง source/candidate ซ้ำ
- refresh token/client secret ไม่ปรากฏใน response, log หรือ repository scan
- imported Drive PDF ผ่าน Document Intake tests ชุดเดียวกับ local PDF

### เกณฑ์ผ่าน

- Admin เลือก PDF จาก Drive แล้วเห็น Document Source/Preview ได้
- Drive import ไม่มีกฎ Catalog แยกชุดใหม่
- disconnect/revocation ทำงานและไม่ลบ Catalog Draft ที่สร้างไปแล้ว
- production credential และ live OAuth ยังถือว่า unverified จนกว่าจะทดสอบใน environment ที่อนุมัติ

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-06-google-drive-manual-import.md`

---

## Feature 7 — Drive Folder Auto-Sync (Optional, ต้องอนุมัติใหม่)

### Stop Condition

Feature นี้อาจต้องมี public webhook callback, channel renewal, scheduler/queue, retry และ deduplication
จึงชนกับ stop conditions ปัจจุบัน ห้ามเริ่มจากการอนุมัติ PDF/Drive Manual Import เพียงอย่างเดียว

### สิ่งที่ต้องออกแบบก่อนอนุมัติ

1. Polling ด้วย scheduled command หรือ Drive `changes.watch`
2. HTTPS callback authentication/channel token
3. Watch renewal และ expiration handling
4. `changes.list` cursor storage และ missed-event reconciliation
5. Duplicate/out-of-order notification handling
6. Update semantics: version ใหม่ต้องสร้าง intake version ใหม่ ไม่ overwrite published catalog
7. Delete/unshare semantics: ห้ามลบ Catalog ที่เผยแพร่โดยอัตโนมัติ
8. Queue/worker topology, retry, dead-letter และ observability
9. Cost/rate limit และ operational ownership

### Required tests

- duplicate/out-of-order/empty notification
- expired channel และ renewal failure
- missed changes recovery
- file update/delete/unshare
- Drive outage และ rate limit
- no automatic Catalog update/delete/publish

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-07-drive-folder-sync.md`

---

## Feature 8 — Document Intake API และ CLI

### เป้าหมาย

สร้าง API contract กลางก่อน MCP เพื่อให้ UI, CLI และ agent clients ใช้ validation/business rules
ชุดเดียวกัน

### API ที่เสนอ

Read operations:

- list/get document sources
- get extraction status
- list/get candidates
- validate candidate
- get review summary โดยไม่คืน raw source เกินสิทธิ์

Draft-only write operations:

- create intake source ผ่าน bounded upload/presigned flow
- request extraction/retry
- edit/reject candidate
- confirm candidate เป็น Draft เมื่อมี explicit authorized approval

ไม่เปิดผ่าน agent API:

- Publish Catalog
- overwrite/update existing code
- delete published Catalog
- raw SQL/general database query
- arbitrary file path/URL fetch

### งาน CLI

สร้าง Laravel Artisan commands ที่เรียก service layer เดียวกับ UI/API เช่น:

- `document:intake-status`
- `document:intake-extract`
- `document:intake-validate`
- `document:intake-cleanup --dry-run`

คำสั่งที่เปลี่ยนข้อมูลต้องมี explicit flags และค่า default เป็น dry-run/read-only เมื่อเหมาะสม

### วิธีตรวจ

- hashed/revocable/expirable scoped API tokens
- read token เรียก write operation ไม่ได้
- draft token Publish ไม่ได้
- rate limit, schema bounds, idempotency และ audit metadata
- CLI กับ UI ให้ validation result เหมือนกัน
- arbitrary path/URL และ SSRF payload ถูกปฏิเสธ

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-08-document-intake-api-cli.md`

---

## Feature 9 — MCP สำหรับ Claude, ChatGPT/Codex, Hermes และ OpenClaw

### เป้าหมาย

ทำ thin MCP facade บน Document Intake API ที่ผ่าน Review แล้ว ไม่ใส่ business logic ชุดใหม่ใน MCP

### Tool surface ที่เสนอ

Read-only tools:

- `list_document_sources`
- `get_document_source`
- `get_extraction_status`
- `list_document_candidates`
- `validate_document_candidate`

Draft-only tools:

- `create_document_intake`
- `request_document_extraction`
- `update_document_candidate`
- `reject_document_candidate`
- `confirm_candidates_as_drafts` โดยต้องใช้ explicit approval/scoped permission

ไม่สร้าง tool สำหรับ Publish ใน milestone นี้

### Security requirements

1. Remote HTTP MCP ใช้ OAuth 2.1/approved authorization pattern ไม่ใช้ shared token ใน prompt
2. แยก read-only และ draft-write scopes
3. Tool schemas จำกัด size/count/enum และ annotate read/write/destructive semantics ถูกต้อง
4. MCP server ไม่มี DB credentials และเรียกเฉพาะ Management API
5. Agent-provided document text ถือเป็น untrusted input
6. Mutating tool ต้องรองรับ client approval และ server-side authorization ทั้งสองชั้น
7. Audit เฉพาะ caller/tool/source ID/status/duration ห้าม log document content
8. Hermes/OpenClaw เป็น client/operator เท่านั้น ไม่เป็น system of record

### วิธีตรวจ

- MCP conformance/tool discovery
- Claude/ChatGPT-compatible remote HTTP connection ใน test environment
- read-only client เรียก write tool ไม่ได้
- write client Publish/overwrite ไม่ได้
- prompt injection ไม่เปลี่ยน tool authorization
- repeated tool call ไม่สร้าง duplicate draft
- unavailable Management API fail closed
- ตรวจ security scan ของ dependencies และ deployment exposure

### เอกสารส่ง Review

`docs/reviews/YYYY-MM-DD-feature-09-document-intake-mcp.md`

หลัง MCP ผ่าน Review จึงค่อยทดลอง Hermes/OpenClaw ด้วย synthetic documents และ restricted account
ห้ามนำ production credentials ไปทดสอบครั้งแรก

---

## 6. Required Test Matrix รวม

### File and parser

- valid text PDF ภาษาไทย
- multiple listings
- scanned/blank/corrupt/encrypted PDF
- wrong extension/MIME/magic bytes
- oversized file/pages/text/candidates
- Thai Unicode, tables, numbers, commas, currency และ area units
- malicious filename/path traversal
- duplicate file hash/version

### AI extraction

- valid structured response
- missing/ambiguous facts
- hallucinated field และ field ที่ไม่มีใน source
- wrong types/enums/extra keys
- prompt injection inside document
- timeout/429/5xx/malformed JSON
- retry/idempotency
- model/schema/prompt version provenance

### Candidate and catalog safety

- preview does not write Catalog
- edit/reject/cancel
- confirm creates Draft only
- duplicate code never overwrites
- concurrent confirm
- invalid category/price/date/URL/availability
- unpublished/inactive/unavailable records never reach Catalog Search/Flex/AI
- existing Excel/CSV contracts remain compatible

### Auth/privacy/operations

- unauthenticated/non-admin/cross-user access
- private source file authorization
- secrets absent from repo, logs and responses
- raw document/prompt/model output absent from logs
- retention/cleanup dry-run and approved deletion behavior
- migration up/down in disposable test database
- service outage and safe retry

### Google Drive/MCP เมื่อถึง Feature นั้น

- OAuth state/PKCE/scope/revocation
- Drive permissions/version/delete/unshare/rate limit
- MCP OAuth/scopes/tool approval/idempotency
- no Publish/overwrite/SQL/file-system escape

## 7. Verification Commands หลังแต่ละ Feature

Focused tests ต้องรันก่อน แล้วจึงรันชุดเต็มที่เกี่ยวข้อง:

```text
apps/management:
  php artisan test --filter=<FocusedTest>
  php artisan test
  npm run typecheck
  npm run lint
  npm run build

services/ai เมื่อ Feature แตะ AI service:
  pytest tests/test_document_extraction.py
  pytest
  python3 -m compileall -q src tests

root configuration เมื่อ Feature แตะ packaging:
  docker compose config
```

ก่อนส่ง Review ต้องบันทึกคำสั่งจริง, exit status, จำนวน test ผ่าน/ไม่ผ่าน และเหตุผลของคำสั่งที่ไม่ได้รัน
ห้ามเขียนเพียง “tests passed” โดยไม่มีหลักฐาน

## 8. รูปแบบ Review Packet ที่ต้องสร้างหลังทุก Feature

ไฟล์: `docs/reviews/YYYY-MM-DD-feature-NN-<slug>.md`

```markdown
# Feature NN Review Packet — <ชื่อ Feature>

## Review status
- Status: AWAITING_REVIEW | APPROVED | CHANGES_REQUESTED
- Requirement IDs:
- Reviewer:
- Date:

## Scope completed
- สิ่งที่ทำจริง
- สิ่งที่ตั้งใจไม่ทำใน Feature นี้

## Changed files
- path — เหตุผลที่เปลี่ยน

## Database and migration effects
- schema changes
- data migration effects
- rollback command/result

## API/configuration changes
- routes/contracts
- new environment variables โดยไม่ใส่ค่าความลับ
- backward compatibility

## Verification evidence
| Command/Test | Result | Evidence/Notes |
|---|---|---|

## Security and privacy review
- auth/authorization
- file validation
- prompt injection/data boundary
- logging and secret scan

## UI evidence
- screenshots หรือ recording จาก synthetic data
- states: success, empty, invalid, unavailable

## Known limitations and risks
- local-only verification
- production items not tested
- remaining decisions

## Reviewer checklist
- [ ] Acceptance criteria satisfied
- [ ] Tests adequate
- [ ] Docs match implementation
- [ ] No secret/PII introduced
- [ ] Migration/rollback acceptable
- [ ] Approved to start next Feature
```

## 9. เอกสารที่ต้องรักษาให้ตรงกับระบบ

อัปเดตเฉพาะเมื่อ Feature ที่เกี่ยวข้องได้รับอนุมัติและ implement จริง:

- `SPEC.md` — canonical requirements/acceptance criteria
- `docs/ARCHITECTURE.md` — ownership, data flow, trust boundaries
- `apps/management/README.md` — setup/admin workflow
- `apps/management/docs/DOCUMENT_INTAKE.md` — file limits, review, retention, errors
- `apps/management/docs/GOOGLE_DRIVE_INTEGRATION.md` — OAuth/scopes/config เมื่อถึง Feature 6
- `services/ai/README.md` — extraction endpoint/model/schema behavior เมื่อถึง Feature 3
- `.env.example` files — เฉพาะชื่อตัวแปร ไม่มี secret/production identifiers
- Review Packet ของแต่ละ Feature — หลักฐาน ณ เวลาส่ง Review

## 10. ความเสี่ยงหลักและวิธีควบคุม

### AI อ่านตัวเลขผิด

- เก็บ page evidence
- strict numeric/unit validation
- mark missing/ambiguous
- human confirmation
- never auto-publish

### PDF ฝัง prompt injection หรือ malformed content

- แยก document data จาก system instructions
- sandbox/bounded parser
- MIME/magic/page/text limits
- schema allowlist และ reject extra keys

### ข้อมูลซ้ำหรือเขียนทับของเดิม

- SHA-256/source version idempotency
- unique `code`
- transaction/concurrency tests
- create-draft-only contract

### ไฟล์หรือ credential รั่ว

- private storage
- authorization on every download/view
- encrypted/secret-managed OAuth tokens
- no body/token logging
- retention and deletion policy

### Feature ขยายเกิน Version 1

- ทำทีละ Review Gate
- Drive auto-sync, queue, OCR provider และ MCP เป็น approvals แยก
- ไม่ใช้ Hermes/OpenClaw เป็นระบบหลัก

### Production timeout จาก synchronous processing

- จำกัด file/pages/text/time ใน MVP
- วัดจริงด้วย fixtures
- หากเกินขอบเขต ให้คืนสถานะ unsupported/too-large
- queue/worker เป็น proposal แยก ไม่เปิดใช้โดยอ้อม

## 11. คำถามที่ Product Owner ต้องอนุมัติก่อน Feature 0/1

1. อนุมัติหลักการว่า AI สร้าง Candidate/Draft ได้ แต่ Publish ต้องเป็นคนหรือไม่
2. MVP รับ text-based PDF ก่อน และ scanned PDF ไป Feature OCR หรือไม่
3. อนุมัติ limit เริ่มต้น 10 MB, 20 หน้า, 20 Candidates หรือให้ปรับเท่าใด
4. หนึ่ง PDF สามารถมีหลายรายการทรัพย์หรือจำกัดหนึ่งรายการในรอบแรก
5. เก็บ source PDF ใน private storage กี่วัน: 30, 90 วัน หรือจนกว่าจะลบด้วยคน
6. หาก `code` ซ้ำ ให้ข้ามอย่างเดียว หรือสร้าง Candidate เพื่อให้คนเปรียบเทียบโดยห้าม update
7. ใช้ OpenRouter model ปัจจุบันสำหรับ extraction หากผล spike ผ่านหรือไม่
8. ต้องรองรับ Knowledge/FAQ จาก PDF ใน milestone แรกหรือเริ่ม Catalog Item อย่างเดียว
9. Google Drive ใช้ user OAuth + Picker หรือ service account + shared folder
10. MCP อนุญาตเพียง read/draft หรือจะมี human-approved confirm-to-draft ด้วย

## 12. ลำดับส่งมอบที่แนะนำ

### Milestone A — PDF MVP

1. Feature 0: Contract/Spike
2. Review และอนุมัติ
3. Feature 1: Secure Upload
4. Review และอนุมัติ
5. Feature 2: Digital Text Extraction
6. Review และอนุมัติ
7. Feature 3: AI Candidate Extraction
8. Review และอนุมัติ
9. Feature 4: Human Review → Draft
10. End-to-end PDF MVP Review

### Milestone B — Source Expansion

1. Feature 5: OCR หากข้อมูลจริงต้องใช้
2. Feature 6: Google Drive Manual Import
3. Controlled test ด้วย synthetic Drive folder

### Milestone C — Agent Access

1. Feature 8: API/CLI
2. Feature 9: MCP
3. ทดลอง Claude/ChatGPT/Codex ก่อนด้วย read-only account
4. ทดลอง Hermes/OpenClaw ภายหลังด้วย restricted draft-only tools

### Milestone D — Optional Automation

1. วัดว่าการนำเข้า manual สร้างภาระจริงหรือไม่
2. ถ้าจำเป็น จึงออกแบบ Feature 7 Drive Folder Auto-Sync
3. ขออนุมัติ public webhook/queue/operations แยกจาก PDF/Drive Manual

## 13. Definition of Done ของโครงการนี้

โครงการถือว่าเสร็จเฉพาะส่วนที่ได้รับอนุมัติเมื่อ:

- requirements และ acceptance criteria ใน `SPEC.md` ตรงกับ implementation
- Admin นำ synthetic PDF เข้าแล้วได้ validated candidates พร้อม page provenance
- Admin แก้/ปฏิเสธ/ยืนยัน Candidate ได้
- Confirm สร้าง Draft ใหม่เท่านั้นและไม่ overwrite
- Draft ไม่ปรากฏแก่ลูกค้าก่อน Publish
- parser, AI, Drive และ MCP failures fail closed
- tests ที่เกี่ยวข้องผ่านและ Review Packet มีหลักฐานครบ
- docs/config/migrations/rollback ตรงกับระบบ
- ไม่มี secret, PII, raw document, prompt หรือ model output ใน log/repository
- production behavior ที่ยังไม่ทดสอบถูกระบุว่า unverified
- Feature ถัดไปไม่ถูกเริ่มก่อน Feature ปัจจุบันได้รับ `APPROVED`

## 14. ข้อเสนอเพื่ออนุมัติรอบแรก

แนะนำให้อนุมัติเพียง **Feature 0 — Contract/Fixtures/Spike** ก่อน ยังไม่อนุมัติ Feature 1–9
แบบรวมชุด เพราะ Feature 0 จะให้หลักฐานว่า parser และ LLM path อ่าน PDF ภาษาไทยได้ดีพอหรือไม่
ก่อนเพิ่ม dependency, migration หรือ production configuration

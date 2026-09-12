# แผนพัฒนา: PDF → External Agent → Management MCP CRUD

สถานะ: Product owner อนุมัติให้พัฒนาตามแผนเมื่อ 2026-09-06; บันทึก FR-AGENT-001–008 ใน SPEC แล้ว เริ่ม Phase 1A typed attributes ก่อน hierarchy/concurrency และ changeset โดยไม่อนุมัติ production migration/deploy โดยปริยาย
วันที่: 2026-09-06
ผู้ตัดสินใจ: Product owner
ผู้พัฒนาและ review: Codex ตาม AGENTS.md ปัจจุบัน ไม่ส่งงานให้ agy

ความคืบหน้า 2026-09-06: Phase 0–5 ทำครบใน development: typed attributes, hierarchy/optimistic version/archive, immutable change sets/evidence/revisions, proposal-only API+MCP/CLI, admin review/apply, opt-in proposal keys และ FAQ/knowledge adapters. Business Profile singleton regression ได้แก้โดยคืน existing profile แทนการสร้าง id 1 ซ้ำ; test ครอบคลุมแล้ว. ดู `docs/reviews/2026-09-06-agent-crud-mcp-phase2-5.md` สำหรับหลักฐานล่าสุดก่อน review. ไม่มี production migration/deploy, real key หรือ real-data pilot.

## 1. คำแนะนำหลัก

ให้ Claude/Codex/Antigravity อ่านไฟล์และเสนอข้อมูล ส่วน Management รับผิดชอบ schema, validation, สิทธิ์, การบันทึกและประวัติ ใช้ MySQL และ Laravel เดิม ไม่ทำ SQL tool และไม่ให้ agent สร้างตารางตามใจ

เริ่มด้วย catalog เป็น vertical slice แรก โดยใช้ COCO PARC เป็นกรณีตรวจแบบมีคนดู และ synthetic fixture เป็น automated tests จากนั้นขยาย FAQ/knowledge ด้วยกลไกเดียวกัน

รูปแบบ UX ที่แนะนำ:

1. ลูกค้าแนบ PDF ให้ agent ที่รองรับอ่านไฟล์นั้น
2. Agent อ่าน schema และค้นข้อมูลเดิมผ่าน MCP
3. Agent ส่ง create/update/archive หลายรายการเป็น changeset เดียว
4. ระบบคืนข้อมูลที่ผ่านตรวจ ความต่าง คำเตือน และลิงก์ review
5. ลูกค้ากดอนุมัติการเปลี่ยนแปลงหนึ่งครั้งต่อชุด
6. ระบบบันทึกจริงแบบ transaction และคืนสรุป หากมีการเผยแพร่ต้องยืนยันแยกอย่างชัดเจน

MVP ไม่ถามอนุมัติทุกฟิลด์ แต่ไม่อนุญาตให้ agent ใช้คำว่า confirmed=true เพื่อข้ามการอนุมัติฝั่ง server

## 2. ข้อเท็จจริงจากโค้ดปัจจุบัน

- `apps/management/app/Models/ServicePackage.php`: ตาราง packages มีคอลัมน์หลักและ attributes JSON อยู่แล้ว
- `apps/management/app/Models/PackageCategory.php`: มี attribute_definitions JSON อยู่แล้ว
- `apps/management/app/Http/Requests/PackageRequest.php`: attributes ยังรับค่าเป็นข้อความเท่านั้น และ prepareForValidation เติม availability=available หากไม่ได้ส่งมา
- `apps/management/app/Http/Requests/PackageCategoryRequest.php`: ยังไม่รับ attribute_definitions ในกฎ validation
- `apps/management/app/Http/Controllers/Api/CatalogSearchController.php`: dynamic filters ยังจำกัดเป็นรายการคอลัมน์ที่โค้ดอนุญาต ไม่ได้ค้น attributes อิสระ
- `apps/management/routes/api.php`: catalog/knowledge เป็น read API; document MCP มี list/get metadata เท่านั้น
- `tools/document-intake-agent/src/mcp.ts`: มี document_list และ document_get ยังไม่มี write tools
- `DocumentSource` มี private source/file hash; HTTP upload ปัจจุบันอยู่หลัง admin session จำกัด PDF 10 MB ไม่มี authenticated MCP upload tool
- FR-CAT-003/004 ระบุ typed category attributes ไว้ แต่ implementation ข้างต้นยังไม่ครบ ต้องบันทึก gap ไม่ถือว่าพร้อมใช้แล้ว
- FR-SETUP-002/004 และเอกสาร tools ปัจจุบันยืนยัน read-only การเปิด write ต้องแก้ SPEC และแสดง permission ใหม่อย่างชัดเจนหลังอนุมัติ

## 3. ขอบเขตที่เสนอให้อนุมัติ

### อยู่ในรุ่นแรก

- Local CLI/MCP สำหรับ Codex, Claude Code และ Antigravity
- Schema discovery, bounded admin search, CRUD proposals แบบชุด, review, apply, archive/restore, audit
- Catalog record แบบข้อมูลกลุ่ม/รายละเอียดประเภท/รายการขายจริง โดยยังใช้ packages เดิม
- Typed attributes ที่ควบคุมด้วยหมวด ไม่ใช่ arbitrary nested JSON
- สถานะ unknown สำหรับข้อมูลที่ยังยืนยันไม่ได้; ไม่บังคับราคา เลขยูนิต หรือ availability จากโบรชัวร์
- เอกสารอ้างอิงต้นทางต่อ field/change พร้อมระบุระดับความน่าเชื่อถือและสิ่งที่ยังต้องยืนยัน
- ต่อด้วย FAQ และ knowledge หลัง catalog slice ผ่าน

### ยังไม่รวม

- Remote MCP/OAuth สำหรับ hosted Work/Cowork: เป็นงานแยก ไม่อ้างว่ารุ่น local จะใช้ใน cloud ได้อัตโนมัติ
- สร้าง OCR/LLM pipeline, vector DB, queue หรือ agent runtime ของเราเอง
- Google Drive sync อัตโนมัติ, PDF download สาธารณะ, arbitrary URL fetch
- หลายธุรกิจ, CRM, การจอง/ชำระเงิน, เปลี่ยน ownership ของ Chatwoot
- AI เปลี่ยน SQL schema, เปลี่ยนชนิดฟิลด์เดิมโดยอัตโนมัติ, ลบถาวร, publish โดยไม่ยืนยัน
- การปรับระบบตอบลูกค้าทั้งระบบเพื่ออ่านโบรชัวร์โดยตรง

## 4. โครงสร้างข้อมูลที่แนะนำ

### 4.1 เก็บฐานเดิม และเพิ่มเฉพาะส่วนที่จำเป็น

| ส่วน | แนวทาง | เหตุผล |
|---|---|---|
| Core fields | ใช้ packages เดิม: ชื่อ รหัส หมวด ราคา ที่ตั้ง สถานะ | ใช้ API/index/การค้นหาเดิมต่อได้ |
| Flexible attributes | attributes JSON + category attribute_definitions ที่มี schema_version | รับข้อมูลแต่ละธุรกิจโดยไม่ ALTER TABLE ทุกครั้ง |
| Record role | เพิ่ม record_kind แบบ group/variant/offer โดย migration เติมแถวเดิมเป็น offer | แยกข้อมูลโครงการ/ประเภทห้องจากรายการขายจริง ไม่ชนความหมาย item_type เดิม |
| Relationships | เพิ่ม parent_id แบบ nullable และกฎลำดับชั้นสูงสุด 3 ระดับ | COCO PARC → ประเภทห้อง → ยูนิต โดยไม่สร้าง CRM ใหม่ |
| Concurrency | เพิ่ม lock_version; ทุก writer ที่เกี่ยวข้องต้องปรับ version | ป้องกัน agent เขียนทับงานคนหรือ batch อื่น |
| Archive | เพิ่ม deleted_at หรือ archived_at โดยเลือกแบบเดียวก่อนพัฒนา | กู้คืนได้ และมีผลกับทุก read/search/export path |
| Change batches | change_sets และ change_operations | เก็บข้อเสนอ immutable payload, revision, สถานะและผลอนุมัติ |
| Provenance/history | record_sources และ record_revisions ตามความจำเป็นของ entity adapter | ตรวจต้นทางและย้อนรอย โดยไม่ใส่ข้อมูลธุรกิจลง application logs |

ชื่อฟิลด์/ตารางใหม่ทั้งหมดเป็นข้อเสนอ ต้องตรวจ migrations/indexes/DB version จริงก่อนกำหนด migration สุดท้าย ไม่ rename/drop ตารางเดิม

### 4.2 Typed attributes รุ่นแรก

- รองรับ string, integer, decimal, boolean, enum, string_list และ numeric_range
- นิยามประกอบด้วย stable key, label, type, unit, nullable, allowed_values, searchable และ allowed_operators
- ตัวอย่าง usable_area_range = {min: 34.5, max: 48, unit: sqm}; ไม่ใช้สตริง "34.5-48" เป็นตัวเลขค้นหา
- หน่วยกำหนดใน schema ห้าม agent สลับ sqm กับ sqw โดยไม่แปลงและบันทึกที่มา
- แยกความหมาย omitted=ไม่แก้, explicit null=ล้างค่าอย่างตั้งใจ, unknown=ยังไม่ยืนยัน พร้อม reason
- Unknown attributes คืน structured validation errors และ schema suggestion; ห้ามทิ้งเงียบหรือบันทึกเป็น active fields ที่ไม่รู้จัก
- AI เสนอ definition ใหม่ได้ แต่ admin อนุมัติชนิด/หน่วยหนึ่งครั้งก่อนใช้ ไม่บังคับลูกค้าออกแบบ JSON เอง
- การเพิ่ม optional definition ทำได้แบบ additive; เปลี่ยนชนิด/ลบ definition ที่มีข้อมูลต้องเป็น migration proposal แยก
- Core กับ attribute ต้องไม่มีความจริงสองชุด เช่นราคา canonical ต้องอยู่ช่องราคา ส่วนค่าธรรมเนียมไม่ลงเป็นราคาขาย
- จำกัดความลึก จำนวน field ความยาวข้อความ และจำนวน list items ฝั่ง API/MCP ทั้งสองชั้น ค่าเริ่มต้นเสนอ 40 attributes/record, 50 operations/batch, 1 MiB JSON/request แล้วปรับจาก tests

### 4.3 แยกโบรชัวร์ออกจาก stock

- ข้อมูลกลุ่มและประเภทเป็นข้อมูลอธิบาย ไม่แปลว่ามีรายการว่างพร้อมขาย
- public catalog search เดิมคืน offer ที่ active/published/effective/available เท่านั้น ไม่คืน group/variant เป็น listing โดยปริยาย
- อนุญาต unknown availability ใน admin/write contract ใหม่ แต่อย่าเปลี่ยน default ของ legacy clients โดยไม่มี compatibility tests
- ถ้าต้องการตอบคำถามเกี่ยวกับ project facts ให้ publish knowledge ที่อ้างอิง group ได้ภายหลัง ไม่ข้ามเงื่อนไข inventory search
- ไม่สืบทอดราคา/availability จาก parent ลง child อัตโนมัติ
- ห้าม cycle, parent=self, parent archived และ parent reference นอกขอบเขตสิทธิ์

## 5. สัญญา CRUD และ changeset

### 5.1 Proposed tools

| Tool | หน้าที่ | มีผลเขียนหรือไม่ |
|---|---|---|
| schema_get | อ่าน entities, fields, constraints, version และตัวอย่าง | อ่าน |
| records_search / record_get | อ่านข้อมูล admin ที่ key มีสิทธิ์ รวม draft และ revision ตาม view ที่ระบุ | อ่าน |
| changes_preview | ตรวจ operation ชุด, diff, field errors และ duplicate candidates | ไม่เปลี่ยน business data |
| changes_submit | บันทึกข้อเสนอเพื่อ review และคืน review URL | เขียน proposal เท่านั้น |
| changes_get | ดูสถานะ proposed/approved/applied/rejected/conflicted | อ่าน |
| schema_propose | เสนอ definition ใหม่ ไม่ activate เอง | เขียน proposal |

Create/update/archive/restore เป็น operation ภายใน changeset เพื่อลด tools ซ้ำ ไม่ต้องมี 20 tools ที่ความหมายทับกัน การ apply อยู่หลัง authenticated admin approval ไม่ให้ key agent อนุมัติข้อเสนอของตนเอง

### 5.2 API contract

- เสนอ namespace `/api/v1/agent/...` แยกจาก read endpoints ที่ Chatwoot ใช้
- Update ใช้ PATCH semantics เฉพาะฟิลด์ที่ส่งมา; nested range replace เป็นก้อนที่ผ่าน validation ไม่ deep-merge แบบคลุมเครือ
- Update/archive/restore ต้องมี target id และ expected_version; server คืน 409 conflict เมื่อ version ไม่ตรง
- Create ใช้ client_ref ภายใน batch; child อ้าง parent client_ref ได้โดยไม่มีการเดา ID
- ทุก submission มี idempotency key และ payload hash ฝั่ง server: key เดิม+payloadเดิมคืนผลเดิม, keyเดิม+payloadต่างคืน conflict
- ชุดที่ admin อนุมัติต้องผูกกับ payload hash/schema version; เปลี่ยน payload ต้องเสนอใหม่
- Apply เป็น transaction all-or-none ในรุ่นแรก พร้อม revalidate permissions, schema และ revisions ภายใน transaction
- Preview ไม่ใช่การ lock ข้อมูล ต้องตรวจซ้ำตอน apply
- Token หมดอายุบล็อกคำสั่งใหม่จาก agent แต่ proposal ที่รับไว้เมื่อ token ยัง valid ไม่หายไป: apply ภายหลังใช้สิทธิ์และการยืนยันของ admin ปัจจุบันโดยตรง ไม่ reuse token ที่หมดอายุ ต้อง revalidate payload/source/versions ทั้งชุด
- หาก token ถูก revoke ด้วยเหตุความปลอดภัย ให้ proposal ที่ยังไม่ apply จาก token นั้นถูก suspend เพื่อให้ admin ตรวจ trust ใหม่ ไม่ approve เงียบ ๆ และไม่ย้อนลบข้อมูลที่เคย apply แล้วโดยอัตโนมัติ
- ถ้าบันทึกสำเร็จแต่ response หาย ให้ query changes_get และ retry ด้วย idempotency key เดิม ไม่สร้างซ้ำ
- Archive parent ที่ยังมี child ต้อง reject พร้อมรายการผลกระทบ ไม่ cascade delete อัตโนมัติ
- Restore ตรวจ unique code/parent/permissions ไม่ override record ใหม่ที่ใช้รหัสเดิม
- Revert history สร้าง changeset ชดเชยที่ตรวจ version ปัจจุบัน ไม่เอา old snapshot มาทับทั้งแถว

### 5.3 สถานะและการเผยแพร่

proposed → reviewed/approved → applied หรือ rejected/conflicted/expired

- Pending changeset ไม่แก้ live row ที่ bot อ่านอยู่
- Create หลัง apply เป็น unpublished draft โดย default
- Update live record แสดงผลกระทบว่าเมื่อ apply แล้ว customer-facing data อาจเปลี่ยน หากยัง published อยู่; ต้องให้ admin ยืนยันชัดเจน หรือเลือกเก็บเป็น revision รอตรวจต่อ
- Publish เป็น action แยกในการ review ไม่ให้ fields.is_published=true จาก agent ข้าม gate
- ไม่เพิ่ม auto-apply mode ใน MVP; ค่อยพิจารณาหลังมีข้อมูลความผิดพลาดจริงและขอบเขตที่ owner อนุมัติ

## 6. Source evidence และ PDF

- Agent รับผิดชอบอ่าน PDF ด้วยความสามารถของ client; หากอ่านไม่ครบ/ไม่มี OCR ให้รายงาน ไม่แต่งข้อมูล
- ใช้ DocumentSource เดิมเมื่อมีต้นฉบับใน Management; ผูก document_id, file_hash ที่ server ตรวจ, page และ field path
- ถ้ามีแต่ไฟล์แนบใน external client ให้ reference-only evidence เป็น unverified จน admin ผูกต้นฉบับ ไม่สร้าง source_id ปลอมหรืออ้างว่า server ตรวจ PDF แล้ว
- MVP อัปโหลดต้นฉบับผ่านหน้า PDF เดิมหนึ่งครั้งได้; ขั้นถัดไปค่อยเพิ่ม MCP upload ด้วย documents:upload หากต้องการ flow ไม่ออกจาก agent
- Upload tool หากทำ: จำกัด 10 MB ตามเดิม, validate PDF/MIME, server คำนวณ hash, private storage, owner authorization และป้องกัน arbitrary server filesystem paths/SSRF; ไม่ยัด base64 PDF ลง schema_get หรือ normal CRUD payload
- การไม่มีหลักฐานไม่ควรบังคับ agent เดา: ให้ค้างเป็น draft/review issue; admin ยืนยันข้อมูล manual ได้พร้อมระบุที่มา
- Quote/snippet จำกัดเฉพาะจำเป็นใน protected evidence store ไม่เก็บ full PDF/text, prompts หรือ customer PII ใน logs
- วันที่ไฟล์หรือ metadata ไม่ใช่หลักฐานว่าราคา/stock ยังเป็นปัจจุบัน
- PDF อาจมี hidden text ต่างจากภาพ: อ้างหน้าและตรวจ visually เมื่อข้อมูลสำคัญขัดกัน ไม่ใช้ extraction score เป็นการรับรองความถูกต้อง
- Automated fixtures ใช้ synthetic PDF/data ไม่ commit brochure ลูกค้าหรือรหัสผ่าน

## 7. สิทธิ์และการแยก agent สองบทบาท

- Customer-facing Chatwoot AI คง read-only เสมอ ไม่ได้รับ management write keys จากงานนี้
- Staff external agent มี agent:read และ changes:write ตามชุดสิทธิ์ที่ admin เลือก รวม entity/action allowlist ฝั่ง server
- schema:propose แยกจากการ activate schema; การ approve/apply/publish เป็น admin session + CSRF
- Token เดิมไม่ถูกเพิ่มสิทธิ์อัตโนมัติ และ key อ่านยังเขียนไม่ได้
- อายุ key เดิม 15/60/240 นาที hash at rest, revoke และ no-store ยังใช้ต่อ
- Source records/knowledge contents เป็น untrusted data ห้ามข้อความใน PDF สั่งเปลี่ยน permissions, tool policy หรือ endpoint
- Audit เก็บ actor token id/admin id, entity id, revision, action, time, batch id และ bounded before/after ใน protected DB history ตาม retention ที่อนุมัติ ไม่ log secrets หรือ raw payload

## 8. ลำดับพัฒนาและเกณฑ์ส่ง review

### Phase 0 — อนุมัติ contract และทำตัวอย่าง mapping

งาน: แก้ SPEC หลังอนุมัติให้มี FR-AGENT-001…008: schema, typed data, source evidence, changeset, permissions, review, compatibility, evaluation; ยืนยัน group/variant/offer และ publish policy

ไฟล์: `SPEC.md`, `docs/decisions/agent-write-contract.md`, `docs/proposals/agent-write-api.md`

ตรวจ: mapping COCO PARC แบบอ่านอย่างเดียวต้องแยก group/room type/fees, ไม่สร้าง unit stock หรือราคาขายจากค่าส่วนกลาง; owner ตรวจตัวอย่างหน้าจอ review และ payload

ส่งมอบ: schema examples, operation examples, permission matrix, decisions log; ยังไม่เปลี่ยน production

### Phase 1 — Typed catalog และ migration แบบ additive

งาน: shared validator, category definitions, record_kind/parent/revision/archive, unknown semantics; แยก write validator จาก default ของ legacy form

ไฟล์เดิม: `apps/management/app/Models/{ServicePackage,PackageCategory}.php`, `app/Http/Requests/{PackageRequest,PackageCategoryRequest}.php`, `app/Http/Controllers/Admin/{PackageController,PackageCategoryController}.php`, `app/Http/Resources/PackageResource.php`, `resources/js/pages/{Categories,Packages}/Form.tsx`

ไฟล์ใหม่ที่คาด: `app/Services/Catalog/AttributeValidator.php`, `app/Services/Catalog/CatalogWriter.php`, `database/migrations/<timestamp>_add_agent_catalog_fields.php`, `tests/Feature/TypedCatalogAttributesTest.php`, `tests/Feature/CatalogCompatibilityTest.php`

ตรวจ: typed values/units/range bounds/null/unknown, invalid definition, unregistered keys, parent cycles, legacy records unchanged, all writers bump version; import/export/form roundtrip ไม่ flatten JSON เป็นสตริงผิด

ส่งมอบ: migration dry-run และ data compatibility report บน VM dev DB, schema examples, tests, rollback procedure แบบไม่ drop คอลัมน์ที่มีข้อมูล

### Phase 2 — Write API และ transaction engine

งาน: entity allowlist เริ่ม catalog; search/get สำหรับ admin; preview/submit/status; changeset/revision/source storage; admin approval/apply service; idempotency, optimistic concurrency, archive/restore

ไฟล์: `routes/api.php`, `app/Http/Controllers/Api/Agent/*`, `app/Http/Requests/Agent/*`, `app/Services/Agent/*`, `app/Models/{ChangeSet,ChangeOperation,RecordRevision,RecordSource}.php`, `database/migrations/<timestamp>_create_agent_change_tables.php`, `app/Http/Middleware/AuthenticateApiToken.php` (ตรวจชื่อจริงก่อนแก้)

Tests ใหม่: `tests/Feature/AgentReadApiTest.php`, `AgentChangesetTest.php`, `AgentPermissionsTest.php`, `AgentConcurrencyTest.php`, `AgentArchiveRestoreTest.php`

ตรวจขั้นต่ำ: read key write=403, expired/revoked=401, unauthorized entity, mass assignment, malformed/oversized payload, duplicate IDs, duplicate submit, idempotency conflict, stale preview, two writers, mid-batch failure rollback, revalidation at approval, no partial write, archive/restore uniqueness, no source/path/secret leak

ส่งมอบ: API contract + error codes/field paths + test output + sample redacted diff; ยังไม่ใช้ข้อมูลลูกค้าจริง

### Phase 3 — MCP สำหรับ staff agents

งาน: เพิ่ม schema/search/changes tools ใน package เดิมอย่าง backward compatible; version bump/package archive ใหม่ คงของเดิมให้ใช้งานต่อได้

ไฟล์: `tools/document-intake-agent/src/{client,mcp,types,cli}.ts`, `src/tests/{client,mcp}.test.ts`, tests ชุด changeset ใหม่, `package.json`, README

ตรวจ: MCP protocol จริงกับ mock API, input/output schemas, read-only/destructive annotations ให้ตรง behavior, bounded responses, timeout/retry idempotency, errors ไม่เปิดเผย secret, document_list/get เดิมยังผ่าน และ tools ไม่เสนอ SQL arbitrary endpoint

ส่งมอบ: archive manifest ไม่มี credentials/test fixtures/private docs, clean install บน VM, synthetic E2E ต่อ staging API; ห้ามอ้างว่า client ทุกตัวผ่านเพราะ package tests ผ่าน

### Phase 4 — Review UX และ key setup

งาน: หน้าแสดงชุดข้อมูลเพิ่ม/แก้/ลบ, diff เฉพาะ changed fields, source/page, warnings, conflict resolution, approve/reject, optional explicit publish, history/archive/restore; รวมข้อสงสัยเป็นชุดเดียว

ไฟล์: `routes/web.php`, `app/Http/Controllers/Admin/AgentChangesController.php`, `resources/js/pages/AgentChanges/*`, `resources/js/pages/AiSetup.tsx`, `app/Http/Controllers/Admin/AiSetupController.php`, `resources/views/docs/*`, `public/docs/agent-setup.md`, `public/skills/document-intake/SKILL.md`, `public/llms.txt`

ตรวจ: guest/nonadmin denied; approval auth/CSRF/replay protection; no auto-publish; old read-only key unchanged; readable diff on mobile/desktop; keyboard focus; missing source; conflict UI; create+parent references; no key in prompts/URLs/history/browser storage

ส่งมอบ: screenshots light/dark/mobile, UI test cases, สรุปสิทธิ์ใหม่แบบภาษาลูกค้า และเอกสาร skill ที่ไม่ขอให้ AI ข้ามการยืนยัน

### Phase 5 — FAQ และ knowledge adapters

งาน: ใช้ changeset engine เดิมเพิ่ม 2 entities ไม่ทำ generic table CRUD; category/schema config และ singleton business profile ยังไม่เปิดแก้ผ่าน key นี้

ไฟล์: `app/Models/{Faq,KnowledgeEntry}.php` และชื่อจริงที่ตรวจพบ, agent entity adapters, validators/resources, history และ existing admin writers

ตรวจ: FAQs ที่ agent สร้างเป็น derived content ระบุแหล่งอ้างอิง; ไม่เปลี่ยนคำแนะนำจาก brochure เป็นนโยบายธุรกิจเอง; revision/archive/public-read filters เหมือน catalog; existing knowledge API backward compatible

ส่งมอบ: multi-entity batch test และเอกสาร mapping PDF → catalog/knowledge/FAQ

### Phase 6 — Pilot และ rollout

1. ใช้ VM dev/staging DB ที่แยกจาก production เท่านั้น และ service ports ที่ไม่ชนของเดิม
2. Synthetic fixtures ทดสอบเต็ม flow; จากนั้น owner อนุมัติการใช้ PDF จริงและการเขียน pilot records แบบร่าง
3. ทดสอบ local clients ทีละตัว: ติดตั้ง → secret setup → discover tools → submit → admin review → apply → verify
4. ตรวจ COCO PARC: ช่วงพื้นที่ถูกต้อง, fees ไม่ใช่ราคาขาย, ไม่มี phantom inventory, import ซ้ำไม่เพิ่มซ้ำ, เอกสารใหม่ไม่ล้างค่าที่ไม่กล่าวถึง
5. แก้ข้อมูลด้วยคนระหว่าง agent ทำงานต้องได้ conflict ไม่ overwrite
6. Request archive โดยไม่มีข้อมูล target ชัดเจนต้องไม่สำเร็จ; archive/restore ต้องไม่รั่วเข้า public search
7. ตรวจ prompt-injection fixture และ source ที่ขาด/ขัดกัน; อาจต้องขอคนยืนยัน ไม่ถือว่า model confidence ผ่าน
8. เปิดให้ธุรกิจเดียว pilot ด้วย write permission แบบ opt-in, ไม่เปลี่ยน key เดิม

ส่งมอบ: per-client pass/fail matrix, observed failures, production approval checklist, backup/rollback proof; ไม่รับรอง AI extraction สมบูรณ์จากตัวอย่างเดียว

## 9. การตรวจงานและเอกสารบังคับทุก Phase

ทุก Phase สร้าง `docs/reviews/<date>-<phase>.md` มี:

- Requirement IDs, ขอบเขตและไฟล์ที่แก้
- Acceptance criteria ทีละข้อพร้อมหลักฐาน/คำสั่ง/exit status
- Tests ที่ผ่าน ไม่ผ่าน และไม่ได้รันพร้อมเหตุผล
- ภาพก่อน/หลังเมื่อ UI เปลี่ยน; redacted API examples เมื่อ contract เปลี่ยน
- Compatibility, migration/backfill, security/privacy และ rollback
- สิ่งที่ reviewer ต้องตรวจและคำถามที่ต้องให้ owner ตัดสิน
- สถานะ ready-for-review ไม่ใช่ production-ready โดยปริยาย

คำสั่งที่วางแผนรันบน VM ใน `apps/management`:

```text
php vendor/phpunit/phpunit/phpunit tests/Feature/<explicit-test-file>.php ...
npm run typecheck
npm run lint
npm run build
```

ใช้ explicit PHPUnit files เพื่อเลี่ยง AppleDouble discovery บน SSD เมื่อจำเป็น; หลังย้ายไป VM Linux ให้รัน full relevant suites ด้วย

ใน `tools/document-intake-agent`: `npm ci`, `npm run typecheck`, `npm test`, `npm run build`, ตรวจ `npm pack` manifest และ clean install

Regression สำคัญ: PackageValidationTest, CatalogSearchApiTest, KnowledgeApiTest, DocumentApiTest, AiSetupTest, AgentDocsTest และ MCP client/protocol tests เดิม; ถ้าแตะ public read contracts ให้เพิ่ม orchestrator regression ใน services/ai โดยไม่มอบ write permissions ให้ runtime นั้น

## 10. Deployment และ rollback

- แผนนี้ไม่อนุมัติ build/deploy/migration เอง เมื่อเริ่ม implementation ต้องยืนยัน environment/action ตาม SPEC และ AGENTS
- ห้ามเปิด Docker บน Mac; dev/build/test หนักทั้งหมดบน VM ที่อนุมัติ ไม่ใช้ production MySQL เป็น test DB
- ดิสก์ Mac มีคำเตือนเต็มจากรอบก่อน ห้ามติดตั้งใหญ่/cleanup/เปลี่ยน disk format โดยอนุมาน
- ตรวจ live release และสถานะใหม่ทุกครั้ง อย่าใช้ path จากความจำแล้ว push ทับ
- สำรอง DB และ source/config ก่อน migration; ทดสอบ restore ใน isolated DB
- ใช้ additive migration และ backfill bounded/idempotent; ประเมิน MySQL locks ก่อน production
- เก็บ compose override, custom-domain HTTPS, secrets และ package artifact เดิม อย่าเผย production IDs ในเอกสาร tracked
- ปิด write feature ได้โดยไม่ปิด read API; rollout แบบ read compatibility ก่อนออก write keys
- Rollback app ต้องใช้ได้กับ schema ที่เพิ่มแล้ว ไม่ down/drop ข้อมูลหรือ restore DB ทับ live data โดยอัตโนมัติ
- Deploy record เก็บบน VM นอก repository พร้อม actual release, image rollback, migration version, checks และผู้อนุมัติ

## 11. ลำดับความสำคัญและจุดตัดสินใจ

### Release A: พิสูจน์แนวทางก่อนขยาย

Phase 0–4 เฉพาะ catalog: typed schema + changeset + MCP + review ใช้ manual source upload เดิม เน้นสร้าง/แก้/กู้คืนที่ตรวจสอบได้ ไม่ทำ Drive/OAuth/OCR พร้อมกัน

### Release B: ขยายชนิดข้อมูล

Phase 5 FAQ/knowledge และ schema proposal UX ที่ดีขึ้น; ทำ source upload ผ่าน MCP หาก pilot พบว่าต้องออกจาก agent แล้วติดขัดจริง

### Release C: ลดขั้นตอนการเชื่อม

Remote MCP + OAuth สำหรับ Work/Cowork, client-account verification และพิจารณา auto-apply ของงานความเสี่ยงต่ำเมื่อ owner กำหนดชัด ไม่เพิ่มเป็นส่วนหนึ่งของ CRUD อย่างเงียบ ๆ

เรื่องที่ขอ owner อนุมัติก่อน dev:

1. รับ approach แบบ hybrid schema และ group/variant/offer แทน generic database builder
2. เริ่ม catalog ก่อน แล้ว FAQ/knowledge ตามมา
3. หนึ่ง admin approval ต่อ batch; agent ไม่มี publish/approve สิทธิ์ตัวเอง
4. Archive/restore แทนลบถาวร; source ที่ยังไม่ยืนยันค้างเป็น review issue
5. อนุมัติ scope ของ VM dev/build แยก production migration/deploy และการใช้ PDF จริงใน pilot

ไม่ให้คำมั่นจำนวนวันจน Phase 0 ยืนยัน migration/compatibility scope; ประเมินใหม่เป็นแต่ละ release ตามงานจริง ไม่ประเมินจากการเพิ่ม MCP function อย่างเดียว

## 12. Definition of Done ของรุ่นแรก

- ลูกค้าส่ง PDF ให้ agent ที่รองรับได้ แล้วได้รับ changeset ที่ตรวจและบันทึกผ่าน MCP โดยไม่กรอกข้อมูลซ้ำในฟอร์ม
- ข้อมูลที่ไม่ทราบไม่ถูกเติมเป็นข้อเท็จจริง ราคา หรือ inventory โดยปริยาย
- Schema/typed validation ใช้ร่วมกันระหว่าง MCP, API และ admin paths ที่เกี่ยวข้อง
- key อ่านเขียนไม่ได้, agent อนุมัติตัวเองไม่ได้, stale/replayed/partial writes ถูกป้องกัน
- แก้และ archive/restore ได้ มี source/revision/audit โดยไม่รั่วความลับ
- ข้อมูลกลุ่ม/ประเภทไม่หลุดเป็นรายการขายใน public API และ behavior Chatwoot เดิมไม่เปลี่ยน
- Build/tests/clean install และ per-client pilot มีหลักฐาน; items ที่ยังไม่ทดสอบระบุชัด
- Docs/skill/UI บอก capabilities ตรงจริง และ owner ผ่าน review ก่อน production rollout

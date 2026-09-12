# MCP และ CLI: เชื่อม AI เข้ากับ Management

MCP เป็นช่องทางให้ AI client เรียกเครื่องมือของ Management ส่วน AI service ใน
`services/ai/` ใช้ตอบแชตลูกค้าผ่าน Chatwoot ทั้งสองใช้งานข้อมูลธุรกิจผ่านขอบเขตของ Laravel
และไม่ต้องให้ AI client เชื่อมฐานข้อมูลโดยตรง

## เลือกวิธีเชื่อมต่อ

### Remote MCP ผ่าน browser

1. เปิด `/admin/ai-setup` บน Management ที่ติดตั้งแล้ว
2. ใช้ URL `https://management.example.com/mcp` โดยเปลี่ยน hostname เป็นของคุณ
3. เพิ่ม URL ใน AI client ที่รองรับ Streamable HTTP และ OAuth
4. ลงชื่อเข้าใช้และยืนยันสิทธิ์ใน browser แล้วให้ client ทดลองเรียกเครื่องมืออ่านข้อมูล
5. กลับมาหน้าเชื่อมต่อ AI เพื่อดูการใช้งาน วันหมดอายุ หรือยกเลิกการเชื่อมต่อ

ต้องเปิด `REMOTE_MCP_ENABLED` และตั้ง HTTPS, Passport signing keys, migrations และ
callback origins ให้พร้อมก่อน ดู [คู่มือ Remote MCP](../../apps/management/docs/REMOTE_MCP.md)
การเปิดหน้าเว็บสำเร็จยังไม่ยืนยันว่า client ผ่าน OAuth และเรียกเครื่องมือได้แล้ว

### Local MCP (stdio) และ CLI

ใช้วิธีนี้เมื่อ AI client เปิด Node process บนเครื่องได้ หรือเมื่อต้องการสั่งงานจาก terminal
ติดตั้งจาก source ภายใน repo:

```bash
cd tools/document-intake-agent
npm ci
npm run build
```

ตั้ง `MANAGEMENT_API_BASE_URL` เป็น origin ของ Management และตั้ง `MANAGEMENT_API_TOKEN`
ผ่าน secret configuration ของเครื่อง/client โดยใช้ key ที่ออกจากหน้า setup ให้ตรงกับงาน
ไม่ต้องส่ง key ในข้อความสนทนา หรือนำไปใส่ไฟล์ที่ commit เข้า Git

คำสั่ง CLI ต่อไปนี้รันจาก `tools/document-intake-agent/` หลังตั้ง environment แล้ว:

```bash
# อ่าน schema และขอบเขตที่ key นี้เข้าถึงได้
node bin/document-intake.js schema

# ตรวจข้อเสนอโดยยังไม่เปลี่ยนข้อมูลธุรกิจ
node bin/document-intake.js changes-preview proposal.json

# ส่งข้อเสนอให้เจ้าหน้าที่ตรวจ; ใช้ idempotency key เดิมเมื่อ retry ข้อเสนอเดิม
node bin/document-intake.js changes-submit proposal.json --idempotency-key example-proposal-001
```

สำหรับ MCP ให้ client เปิด `node` พร้อม argument เป็น absolute path ของ
`tools/document-intake-agent/bin/document-intake-mcp.js` และส่ง environment สองตัวข้างต้น
server นี้ใช้ stdin/stdout และไม่เปิด HTTP port ดู [คู่มือ package](../../tools/document-intake-agent/README.md)
สำหรับคำสั่งเพิ่มเติมและรูปแบบ config ตาม client

## AI ทำอะไรได้บ้าง

| เครื่องมือ | หน้าที่ |
| --- | --- |
| `agent_schema` | อ่าน schema, entity และข้อจำกัดที่อนุญาต |
| `agent_records_search`, `agent_record_get` | ค้น/อ่านข้อมูลและเวอร์ชันข้อมูลตามสิทธิ์ |
| `agent_changes_preview` | ตรวจข้อเสนอและเงื่อนไขก่อนส่ง |
| `agent_changes_submit` | เก็บข้อเสนอเพื่อรอ review ไม่เขียนข้อมูลธุรกิจจริงทันที |
| `agent_changes_get` | อ่านสถานะข้อเสนอที่อยู่ในขอบเขตของการเชื่อมต่อนั้น |
| `document_list`, `document_get` | อ่าน metadata เอกสารเดิมตามสิทธิ์ ไม่อ่านเนื้อหา PDF |

รายการเครื่องมือจริงขึ้นกับสิทธิ์ที่ได้รับ เจ้าหน้าที่ตรวจและใช้ข้อเสนอที่
`/admin/agent-changes`; รายการที่สร้างใหม่เป็น draft เครื่องมือไม่มีการอนุมัติ ใช้งาน
เผยแพร่ หรือ SQL ให้ AI ข้ามขั้นตอนนี้เอง

สำหรับลูกค้าที่มี PDF: ส่ง PDF ให้ AI client อ่าน แล้วให้ client ใช้ schema ของระบบประกอบ
ข้อเสนอพร้อมแหล่งอ้างอิง อ่าน [skill สำหรับข้อเสนอ](../../apps/management/public/skills/management-proposals/SKILL.md)
เพื่อจัดการข้อมูลที่ไม่ทราบและหลักฐานอย่างถูกต้อง หน้า upload `/admin/documents` ถูกถอดแล้ว
ส่วน API metadata เดิมยังเก็บไว้สำหรับความเข้ากันได้

## Source อยู่ที่ไหน

| ส่วน | ไฟล์/โฟลเดอร์ |
| --- | --- |
| Remote MCP + OAuth routes | [routes/ai.php](../../apps/management/routes/ai.php) |
| Laravel MCP tools | [app/Mcp/](../../apps/management/app/Mcp/) |
| HTTP API ที่ local CLI/MCP เรียก | [routes/api.php](../../apps/management/routes/api.php) |
| Local MCP server | [src/mcp.ts](../../tools/document-intake-agent/src/mcp.ts) |
| CLI | [src/cli.ts](../../tools/document-intake-agent/src/cli.ts) |
| HTTP client | [src/client.ts](../../tools/document-intake-agent/src/client.ts) |
| หน้า onboarding | [AiSetup.tsx](../../apps/management/resources/js/pages/AiSetup.tsx) |

**Release Steward** เป็น agent ที่ดูแล Git/CI ตาม [คู่มือ release](../operations/release-steward.md)
แยกหน้าที่จาก MCP สำหรับข้อมูลธุรกิจ และจาก Python AI ที่ตอบข้อความลูกค้า

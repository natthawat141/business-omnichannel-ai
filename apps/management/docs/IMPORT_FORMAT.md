# รูปแบบ Excel/CSV สำหรับนำเข้ารายการทรัพย์

ระบบรองรับการนำเข้ารายการทรัพย์จากไฟล์ Excel หรือ CSV และยังรองรับไฟล์แพ็กเกจ legacy
9 คอลัมน์เดิมเพื่อความเข้ากันได้ย้อนหลัง ชื่อภายในบางส่วนยังใช้ `packages` แต่หน้า Management
และไฟล์แบบใหม่ใช้ workflow อสังหาริมทรัพย์

## หลักความปลอดภัย

- ระบบตรวจไฟล์และแสดงตัวอย่างก่อนเก็บข้อมูลจริง
- `code` เป็นรหัสประจำรายการที่ต้องกรอกและต้องไม่ซ้ำ
- หาก `code` มีอยู่ในระบบหรือซ้ำในไฟล์เดียวกัน ระบบจะ **ข้ามและไม่เขียนทับข้อมูลเดิม**
- ก่อนยืนยันต้องกด “ยืนยันเพิ่ม” และยืนยันในกล่องเตือนอีกครั้ง
- รายการใหม่ถูกเพิ่มเป็น **ฉบับร่าง** (`is_published = false`) และจะไม่ถูกนำไปตอบทันทีจนกว่าจะเปิดเผยแพร่
- เมื่อพร้อมใช้งานให้ไปหน้า “รายการทรัพย์” ตรวจทานอีกครั้งแล้วเปิด “เผยแพร่”

## รูปแบบไฟล์

- รองรับ `.xlsx`, `.xls`, `.csv` ขนาดไม่เกิน 5 MB
- Excel อ่านเฉพาะชีตแรก
- CSV ต้องเป็น UTF-8 และคั่นด้วย comma (`,`)
- ห้ามเปลี่ยนลำดับคอลัมน์
- ช่องที่ไม่บังคับสามารถเว้นว่างได้
- วันที่ใช้รูปแบบ `YYYY-MM-DD`

### หัวตารางปัจจุบัน (24 คอลัมน์)

เพิ่ม `attributes` เป็นคอลัมน์สุดท้าย เก็บ JSON หนึ่ง object ต่อเซลล์ เช่น
`{"area_range":{"min":34.5,"max":48,"unit":"sqm"}}` โดยหมวดนั้นต้องมี typed definition
ที่ admin อนุมัติแล้ว ข้อมูลชนิดอื่นหรือ key ที่ไม่ได้ลงทะเบียนจะไม่ผ่านทั้ง preview และ confirm
ช่องว่างหมายถึงไม่มีข้อมูลเพิ่มเติม ไม่เติมเป็น 0/false หรือข้อมูลว่างพร้อมขาย

```text
code,category_slug,transaction_type,availability,name_th,description_th,price,sale_price,location_text,province,district,subdistrict,project_name,bedrooms,bathrooms,usable_area_sqm,land_area_sqw,floor,primary_image_url,effective_from,effective_until,terms,keywords,attributes
```

CSV ต้อง quote เซลล์ JSON และ escape double quote ตาม CSV เช่น
`"{""area_range"":{""min"":34.5,""max"":48,""unit"":""sqm""}}"`
การ export/import ข้ามระบบต้องมีหมวดและ schema ที่เข้ากันได้ก่อน ไม่สร้าง schema จาก JSON อัตโนมัติ
MySQL อาจเรียง key ใหม่ แต่ชนิดและค่าของข้อมูลต้องไม่เปลี่ยน

หมวด legacy (schema_version=1) ยังรับเฉพาะค่า string/null ตามเดิม ไม่แปลงเป็น typed อัตโนมัติ
ดู [Typed catalog contract](TYPED_CATALOG.md) สำหรับขอบเขตและข้อจำกัด

### หัวตารางแบบเดิม (23 คอลัมน์ ยังรับได้)

```text
code,category_slug,transaction_type,availability,name_th,description_th,price,sale_price,location_text,province,district,subdistrict,project_name,bedrooms,bathrooms,usable_area_sqm,land_area_sqw,floor,primary_image_url,effective_from,effective_until,terms,keywords
```

### หัวตาราง legacy (เก่า)

```text
code,name_th,description_th,price,sale_price,effective_from,effective_until,terms,keywords
```

| Column | Required | Description | Example |
|---|---:|---|---|
| `code` | Yes | รหัสไม่ซ้ำ ใช้ตรวจซ้ำ | `CONDO-2026-001` |
| `category_slug` | No | slug ของประเภททรัพย์ (เช่น condo) ต้องมีอยู่ในระบบ หากมีให้เชื่อมให้โดยอัตโนมัติ | `condo` |
| `transaction_type` | No | ประเภทการโอน/ค้นหา: `sale`, `rent`, `service` | `sale` |
| `availability` | No | สถานะพร้อมขาย: `available`, `reserved`, `sold`, `rented`, `unavailable` | `available` |
| `name_th` | Yes | ชื่อรายการทรัพย์ | `คอนโดใจกลางเมือง 2 ห้องนอน` |
| `description_th` | No | รายละเอียดที่ AI จะอ้างอิงตอบลูกค้า | `ห้องกว้าง มีที่จอดรถ` |
| `price` | No | ราคาหรือราคาอ้างอิงตัวเลข ไม่ใส่ comma | `2850000` |
| `sale_price` | No | ราคาโปรโมชัน | `2700000` |
| `location_text` | No | คำอธิบายทำเลสำหรับค้นหา | `สุขุมวิท ซอย 31` |
| `province` | No | จังหวัด | `กรุงเทพมหานคร` |
| `district` | No | อำเภอ/เขต | `เขตวัฒนา` |
| `subdistrict` | No | ตำบล/แขวง | `บางจาก` |
| `project_name` | No | ชื่อโครงการ | `สยามพาร์ค` |
| `bedrooms` | No | จำนวนห้องนอน | `2` |
| `bathrooms` | No | จำนวนห้องน้ำ | `2` |
| `usable_area_sqm` | No | พื้นที่ใช้สอย ตร.ม. | `72.5` |
| `land_area_sqw` | No | พื้นที่ดิน ตร.ว. | `90` |
| `floor` | No | ชั้น | `12` |
| `primary_image_url` | No | URL รูปหลัก (ต้องเป็น HTTPS) | `https://example.com/image.jpg` |
| `effective_from` | No | วันเริ่มใช้งาน | `2026-08-01` |
| `effective_until` | No | วันสิ้นสุดใช้งาน | `2026-12-31` |
| `terms` | No | เงื่อนไข/ข้อจำกัด | `โอนภายในโครงการเท่านั้น` |
| `keywords` | No | คำค้น คั่นด้วย comma | `คอนโด,ใจกลางเมือง` |

## ขั้นตอนนำเข้า

1. ดาวน์โหลดไฟล์ตัวอย่าง/ไฟล์เปล่าจากหน้า “นำเข้ารายการทรัพย์”
2. กรอกข้อมูล โดยไม่แก้ชื่อหรือสลับคอลัมน์
3. เลือกไฟล์ และกด “ตรวจสอบไฟล์”
4. ตรวจจำนวน เพิ่มใหม่ / รหัสซ้ำ / ข้อมูลผิด
5. กดยืนยันเพิ่ม และยืนยันในกล่องเตือน
6. เปิดหน้า “รายการทรัพย์” เพื่อตรวจข้อความและเปิดเผยแพร่

## ผลหลังนำเข้า

- ถ้ามี `category_slug` ระบบจะเชื่อมกับประเภททรัพย์ที่มี slug ตรงกัน; slug ที่ไม่มีในระบบจะไม่ผ่านการตรวจ
- `transaction_type` แบบ `sale` หรือ `rent` ใช้เป็นเงื่อนไขค้นหาซื้อ/เช่า
- รายการที่ `availability` ไม่ใช่ `available` จะไม่ปรากฏใน Catalog Search หรือ Property Flex
- `primary_image_url` ที่เป็น HTTP หรือ URL ไม่ถูกต้องจะไม่ผ่านการตรวจ
- การ Import ไม่อัปโหลดหรือเก็บไฟล์รูป ระบบเก็บเฉพาะ URL รูปหลักหนึ่ง URL

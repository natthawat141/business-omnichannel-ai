# สำรองข้อมูลและตรวจสุขภาพบน VM เครื่องเดียว

Version 1 รันบน VM ธรรมดาเครื่องเดียว งบดูแลน้อย จึงไม่ใช้ Terraform, Pulumi, Kubernetes
หรือชุดมอนิเตอร์แยกอย่าง Prometheus/Grafana เครื่อง VM สร้างครั้งเดียวจากหน้าคอนโซลของผู้ให้บริการ
ส่วนที่ต้องทำซ้ำได้คือ Docker Compose ใน repo นี้ กับ timer สองตัวบนเครื่อง

บัคเก็ตเดียวใช้ได้ทั้ง Cloudflare R2 และ Oracle Cloud Object Storage เพราะทั้งคู่พูด S3 API
ไม่ต้องเลือกผู้ให้บริการในโค้ด และไม่ต้องเปิดบริการสำรองของคลาวด์เพิ่ม

## สิ่งที่ตั้งใจไม่ทำ

- ไม่เขียน Terraform สำหรับ VM เครื่องเดียว เพราะ state file และสิทธิ์คลาวด์กลายเป็นของที่ต้องดูแล
- ไม่เพิ่มคอนเทนเนอร์มอนิเตอร์ ดูสถานะจาก `systemctl --failed` และ webhook เดียวเมื่อสถานะเปลี่ยน
- ไม่ใส่คีย์บัคเก็ตใน `.env` ราก เพราะ Compose ส่งไฟล์นั้นเข้าคอนเทนเนอร์ของแอป

## ตั้งครั้งเดียว

1. ติดตั้งสแตกตาม [README](../../README.md) จน `docker compose ps` ขึ้นครบ
2. สร้างบัคเก็ตส่วนตัว ปิดการเข้าถึงสาธารณะ
3. คัดลอกแล้วกรอกค่า:

```bash
cp infra/ops/backup.example runtime/backup.env
chmod 600 runtime/backup.env
```

| ผู้ให้บริการ | `BACKUP_S3_ENDPOINT` | `BACKUP_S3_REGION` |
| --- | --- | --- |
| Cloudflare R2 | `https://ACCOUNT_ID.r2.cloudflarestorage.com` | `auto` |
| Oracle Cloud Object Storage | `https://NAMESPACE.compat.objectstorage.REGION.oraclecloud.com` | ชื่อรีเจียน เช่น `ap-singapore-1` |

4. ติดตั้ง timer ต้องเป็น root และผู้ใช้ที่รันต้องอยู่ในกลุ่ม `docker`:

```bash
sudo ./infra/ops/install-timers.sh
./infra/ops/backup.sh --check
./infra/ops/backup.sh
```

`--check` ตรวจว่าไฟล์ตั้งค่าครบโดยไม่พิมพ์รหัสลับและไม่สำรองข้อมูล
คำสั่งสำรองจริงครั้งแรกยืนยันว่าอัปโหลดได้ หลังจากนั้น timer `omnichannel-backup.timer`
ทำงานทุกวันเวลา 03:15 UTC และ `omnichannel-monitor.timer` ตรวจทุก 5 นาที

ถ้าเครื่องไม่มี systemd สคริปต์ติดตั้งจะพิมพ์บรรทัด crontab ให้แทน

## สิ่งที่อยู่ในแต่ละชุดสำรอง

แต่ละชุดอยู่ที่ `s3://BUCKET/PREFIX/YYYYMMDDTHHMMSSZ/`

- `management-mysql.sql.gz` — ข้อมูลธุรกิจ เป็นต้นทางที่ใช้กู้
- `chatwoot-postgres.dump` — บทสนทนาใน Chatwoot
- `chatwoot-redis-data.tar.gz` — คิวและคีย์กันตอบซ้ำ อาจมีเนื้อข้อความลูกค้า
- `chatwoot-storage.tar.gz` และ `management-storage.tar.gz` — ไฟล์แนบในเครื่อง เมื่อ `BACKUP_INCLUDE_FILES=true`
- `checksums.sha256`

ค่าเริ่มต้นเก็บบัคเก็ต 14 วัน แล้วลบชุดที่เก่ากว่า สคริปต์จะไม่ลบชื่อโฟลเดอร์ที่ไม่ใช่รูปแบบเวลานี้
ใบรับรองของ Caddy ไม่ถูกสำรอง เพราะออกใหม่ได้เมื่อเริ่มระบบ

การกู้ Redis ไม่ทำอัตโนมัติ หลังกู้ฐานข้อมูล คิวที่ค้างและหน้าต่างกันข้อความซ้ำ 7 วันอาจว่าง
ข้อความที่แพลตฟอร์มส่งซ้ำในช่วงนั้นอาจถูกประมวลผลอีกครั้ง ต้องตรวจใน Chatwoot ก่อนส่งซ้ำเอง

## แจ้งเตือน

ตั้ง `ALERT_WEBHOOK_URL` เป็น HTTPS เท่านั้น ว่างไว้ได้ ระบบยังบันทึกความล้มเหลวใน journal
เนื้อหาที่ส่งมีแค่สถานะ เหตุผล และชื่อเครื่อง ไม่มีข้อความลูกค้าและไม่มีรหัสลับ
รองรับฟิลด์ `text` และ `content` เพื่อให้ Slack กับ Discord แสดงข้อความได้
หัวข้อส่วนตัวของ ntfy ใช้ได้ถ้าใส่ URL แบบ `https://ntfy.sh/...`

เหตุผลที่ทำให้สถานะไม่ปกติ: คอนเทนเนอร์ไม่รันหรือ health ไม่ผ่าน ดิสก์ถึง `MONITOR_DISK_PERCENT`
(ค่าเริ่มต้น 85) ยังไม่มี `runtime/backup.env` สำรองเก่ากว่า `BACKUP_MAX_AGE_HOURS`
(ค่าเริ่มต้น 30 ชั่วโมง) หรือรอบสำรองล่าสุดล้มเหลว
แจ้งซ้ำเฉพาะเมื่อเหตุผลเปลี่ยน หรือครบ `ALERT_REPEAT_HOURS` (ค่าเริ่มต้น 12)
เมื่อกลับมาปกติจะแจ้งอีกครั้งเดียว

```bash
systemctl list-timers 'omnichannel-*'
systemctl status omnichannel-monitor.service
journalctl -u omnichannel-backup.service -n 50 --no-pager
```

## กู้คืน

กู้คืนทับข้อมูลบนเครื่องนี้ ต้องหยุดตัวที่เขียนข้อมูลก่อน และต้องพิมพ์คำยืนยันตรงตัว
ให้สำรองชุดปัจจุบันก่อน ถ้าข้อมูลบนเครื่องยังมีค่า

```bash
RESTORE_CONFIRM=replace-live-data ./infra/ops/restore.sh 20260926T031500Z
```

สคริปต์ตรวจ checksum ก่อนหยุดบริการ แล้วแทนที่ MySQL และ PostgreSQL
ถ้าชุดนั้นมีไฟล์แนบ จะแทนที่วอลุ่มไฟล์ด้วย
Redis จะถูกแทนที่ก็ต่อเมื่อสั่ง `RESTORE_REDIS=yes` เพิ่ม
เมื่อจบ สคริปต์ไม่เปิด AI, Management และ Chatwoot กลับให้เอง
เปิดเมื่อตรวจข้อมูลแล้วเท่านั้น:

```bash
docker compose start ai ai-worker management-php management-nginx chatwoot-rails chatwoot-sidekiq
```

## สิ่งที่ยังไม่ได้ตรวจบนเครื่องจริง

สคริปต์ตรวจสอบใน CI ว่าการเลือกชุดเก่า การปฏิเสธค่าตั้งค่า และการตัดสินสถานะทำงานโดยไม่ใช้ Docker
ยังไม่ได้อัปโหลดขึ้น R2 หรือ Oracle จริง และยังไม่ได้ซ้อมกู้บน VM ที่รันอยู่
ก่อนให้ข้อมูลจริงพึ่งพาสแตกนี้ ต้องรันสำรองหนึ่งครั้งและซ้อมกู้บนข้อมูลทดลอง แล้วบันทึกผลไว้นอก repo

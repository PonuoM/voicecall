# ops — ตัวเดินงาน 24/7 + เครื่องมือ dev

## ⚠️ ก่อนรัน SQL อะไรก็ตามที่มีข้อความไทย — ใช้ `ops/db.php` ไม่ใช้ `mysql.exe` CLI ตรงๆ

`mysql.exe` บนเครื่อง dev นี้ (Windows) ทำข้อความไทยเพี้ยนไปแล้ว **3 ครั้ง** ในคืนเดียว — ทั้งแบบ
`-e "..."` และแบบ `< file.sql` — โดยที่ **ไม่ขึ้น error เลยแม้แต่ครั้งเดียว** ข้อมูลแค่เพี้ยนเงียบๆ
ใน DB

ตรวจแล้วพบว่าไม่ใช่บั๊กของ `mysql.exe` เอง แต่เป็นเพราะ **console codepage ของ Windows** ที่ใช้ร่วมกัน
ระหว่าง Bash กับ PowerShell — ถ้ามีอะไรไปเปลี่ยน codepage ไว้ (เช่นเรียก PowerShell ก่อนหน้า)
มันจะค้างอยู่แบบนั้นจนกว่าจะมีอะไรมาเปลี่ยนกลับ **ไม่มีสัญญาณเตือนว่ากำลังจะพัง** คำสั่งหน้าตา
เหมือนเดิมทุกตัวอักษร บางทีก็ถูก บางทีก็เพี้ยน

**ทางแก้ถาวร:** `ops/db.php` ต่อ DB ผ่าน PDO ล้วน — ไม่แตะ console layer ของ Windows เลย เพราะงั้น
เชื่อถือได้ 100% (ทดสอบแล้วมากกว่า 30 ครั้งตลอดคืน ไม่พังสักครั้ง เทียบกับ `mysql.exe` CLI ที่พัง 3
ใน ~10 ครั้งที่มีข้อความไทย)

```bash
php ops/db.php prod -e "SELECT COUNT(*) FROM conversations"
php ops/db.php prod migrations/014_company2_compliance_rules.sql
php ops/db.php local -e "SELECT * FROM conversations LIMIT 5" --out=result.json
```

`<target>` คือ `local` (เครื่อง dev) / `prod` (primacom_voicelog) / `erp` (primacom_mini_erp)

---

รันบน VPS (`187.77.127.28`) ผ่าน systemd timer ไม่ใช่ cron ของ DirectAdmin

## ทำไมอยู่ที่นี่

เครื่องนี้เปิดตลอดอยู่แล้ว มี root ให้ดู log ได้จริง และ**อยู่คนละ IP กับ prima49** — Google Drive
บล็อก IP ที่ดาวน์โหลดรัวเกินไป (เจอจริงตอนโหลด 45 ไฟล์รวด แล้วโดนหน้า "Sorry" ระดับ IP ซึ่ง
backoff 244 วินาทีก็ยังไม่ผ่าน) การย้ายภาระดาวน์โหลดออกจากเครื่องเว็บจึงช่วยกระจายความเสี่ยง

worker แค่เรียก cron endpoint ของ prima49 ตามลำดับ ไม่มีตรรกะธุรกิจของตัวเอง — ตรรกะทั้งหมด
ยังอยู่ใน `api/cron/*.php` ที่เดียว

## ลำดับในแต่ละรอบ

1. `sync_gdrive_index` — ต้องมาก่อน ไม่งั้นตัวเลือกสายจะไม่เห็นสายของวันนี้
2. `reap_stale` — คืนคิวสายที่ค้างกลางทาง (รอบก่อนตายกลางคัน) กลับเป็น pending
3. `process_pending` — เก็บตกสายที่ค้างในคิวปกติ
4. `backlog_drain` — ไล่เก็บ backlog ทั้งหมด ใหม่ไปเก่า จนกว่าทุกไฟล์จะมีผลลัพธ์

`smart_sampling` ไม่ได้อยู่ในรอบแล้ว — มันสุ่มจากกรอบ 7 วันซึ่งทิ้งของเก่าถาวร `backlog_drain`
ครอบคลุมงานเดียวกันและจบได้จริง (ดู commit ที่เพิ่มมันสำหรับรายละเอียด)

## จังหวะ

ทุก 20 นาที ครั้งละ 3 สาย ≈ **216 สาย/วัน** ต่ำกว่าเพดาน `SAMPLING_MAX_CALLS_PER_DAY=800` มาก

ทีละน้อยแต่บ่อยเป็นเรื่องตั้งใจ — เว้นที่ให้โควตา MiniMax, CPU สองคอร์ที่แชร์กับ Rocket.Chat/Jitsi
และอัตราดาวน์โหลดที่ Drive ยอมรับ ทั้งยังทำให้ HTTP request ไม่ยาวจนโดนตัด

## ดูสถานะ

```bash
systemctl list-timers voicecall-worker.timer
tail -20 /opt/voicecall/worker.log
journalctl -u voicecall-worker.service --since "1 hour ago"
```

`worker.log` เก็บเฉพาะบรรทัดสรุปของแต่ละรอบ และตัดเหลือ 2000 บรรทัดล่าสุดทุกครั้ง

## หยุด / เริ่ม

```bash
systemctl stop voicecall-worker.timer      # หยุดชั่วคราว
systemctl disable --now voicecall-worker.timer
systemctl start voicecall-worker.service   # รันทันที 1 รอบ
```

`/opt/voicecall/.cron_key` เก็บ `CRON_HTTP_KEY` (โหมด 600) — ไม่อยู่ใน repo

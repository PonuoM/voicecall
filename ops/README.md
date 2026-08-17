# ops — ตัวเดินงาน 24/7

รันบน VPS (`187.77.127.28`) ผ่าน systemd timer ไม่ใช่ cron ของ DirectAdmin

## ทำไมอยู่ที่นี่

เครื่องนี้เปิดตลอดอยู่แล้ว มี root ให้ดู log ได้จริง และ**อยู่คนละ IP กับ prima49** — Google Drive
บล็อก IP ที่ดาวน์โหลดรัวเกินไป (เจอจริงตอนโหลด 45 ไฟล์รวด แล้วโดนหน้า "Sorry" ระดับ IP ซึ่ง
backoff 244 วินาทีก็ยังไม่ผ่าน) การย้ายภาระดาวน์โหลดออกจากเครื่องเว็บจึงช่วยกระจายความเสี่ยง

worker แค่เรียก cron endpoint ของ prima49 ตามลำดับ ไม่มีตรรกะธุรกิจของตัวเอง — ตรรกะทั้งหมด
ยังอยู่ใน `api/cron/*.php` ที่เดียว

## ลำดับในแต่ละรอบ

1. `sync_gdrive_index` — ต้องมาก่อน ไม่งั้นตัวเลือกสายจะไม่เห็นสายของวันนี้
2. `smart_sampling` — เลือกสายเสี่ยง + สุ่ม แล้วส่งเข้า pipeline
3. `process_pending` — เก็บตกสายที่ค้าง

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

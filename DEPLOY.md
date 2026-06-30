# Deploy Guide — voicecall AI pipeline → www.prima49.com/voicecall

ไม่มี build step (plain PHP/HTML) — อัปโหลดไฟล์ตรง ไม่ต้อง `npm run host:build` แบบ primawell

## 📋 Target Server

| Field | Value |
|-------|-------|
| URL | https://www.prima49.com/voicecall |
| Server | (เดียวกับ primawell) Apache + PHP 7.3.10 |
| DB | MySQL — สร้างใหม่ผ่าน DirectAdmin (เช่น `primacom_voicecall`) |
| Path | `/domains/prima49.com/public_html/voicecall/` |
| Upload via | FTP (FileZilla) — เหมือน primawell |

`index.html`/`login.php` มีอยู่บน path นี้แล้ว (ของเดิม, ทำงานอยู่) — งานนี้คือ**เพิ่ม** `api/` +
**แก้** 3 ไฟล์เดิม ไม่ใช่ deploy ใหม่หมด

---

## Step 1 — สร้าง Production DB (ผ่าน DirectAdmin)

1. DirectAdmin → MySQL Management → สร้าง database ใหม่ (เช่น `primacom_voicecall`) + user + password
   จด host/user/pass/dbname ไว้ (ปกติ DB host บน shared hosting คือ `localhost` จากฝั่ง PHP บน
   host เดียวกัน)
2. เปิด phpMyAdmin ของ DirectAdmin → เลือก DB ที่สร้าง → tab **Import** → import เรียงตามลำดับ:
   - `migrations/001_init_voicecall_ai.sql`
   - `migrations/002_seed_compliance_rules.sql`
   - `migrations/003_erp_grounding.sql`
   - `migrations/004_gdrive_file_index.sql`

   ⚠️ ไฟล์ทุกตัวมี `USE voicecall_ai;` ที่หัวไฟล์ — **ต้องลบบรรทัดนั้นออกก่อน import** (หรือแก้เป็น
   `USE primacom_voicecall;` ให้ตรงกับชื่อ DB จริงที่สร้างบน host) ไม่งั้นจะ import เข้าฐานผิดหรือ error
   ว่าไม่มีฐานชื่อ `voicecall_ai`

## Step 2 — เตรียมไฟล์ `.env` สำหรับ Production

**ห้ามอัปโหลด `.env` จาก local ตรงๆ** (DB credentials ในนั้นชี้ไป local MySQL ใช้บน host ไม่ได้)
สร้างไฟล์ `.env` ใหม่บน host (วางที่ `/domains/prima49.com/public_html/voicecall/.env` — ระดับ
เดียวกับ `login.php`) เนื้อหา:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=primacom_voicecall          # ชื่อจริงที่สร้างใน Step 1
DB_USER=<user ที่สร้างใน Step 1>
DB_PASS=<password ที่สร้างใน Step 1>

ERP_DB_HOST=202.183.192.218
ERP_DB_PORT=3306
ERP_DB_NAME=primacom_mini_erp
ERP_DB_USER=primacom_bloguser
ERP_DB_PASS=<รหัสผ่าน ERP DB จริง - ดูได้จาก .env ที่มีอยู่แล้ว ไม่ commit ค่าจริงไว้ที่นี่>

OPENROUTER_API_KEY=<ใช้ key เดียวกับ local หรือสร้างคีย์ใหม่แยกสำหรับ production>
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=google/gemini-2.5-flash-lite
OPENROUTER_COMPLIANCE_MODEL=google/gemini-2.5-flash
OPENROUTER_STT_MODEL=openai/whisper-large-v3
OPENROUTER_EMBEDDING_MODEL=openai/text-embedding-3-small

GDRIVE_API_KEY=AIzaSyCCIywRsoHuBzVTm-B-FA8N7VzAcECIEBE
GDRIVE_ROOT_FOLDER_ID=135GAP4FYM7b7LwVaVwdBHPFYUwn7T5rx
```

(ค่า `ERP_DB_*` / `GDRIVE_*` ใช้ค่าเดิมได้เลย ไม่ใช่ความลับระดับสูง — เป็น key เดิมที่ฝังอยู่ใน
`index.html` ฝั่ง client อยู่แล้ว)

## Step 3 — Upload ผ่าน FileZilla

**Local panel** (`C:\AppServ\www\voicecall\`) → ลาก-วางเฉพาะรายการนี้ไปที่ **Remote panel**
(`/domains/prima49.com/public_html/voicecall/`):

```
api/                  ← ทั้งโฟลเดอร์ (ใหม่)
  Agents/
  Controllers/
  Services/
  Pipeline/
  core/
  cron/
  certs/              ← ต้องมี cacert.pem (OpenRouter HTTPS ต้องใช้)
  config.php
  index.php
  (ไม่ต้องสร้าง storage/audio_cache/ หรือ logs/ — PHP จะสร้างเองตอนรันถ้า host อนุญาตเขียนไฟล์)
.env                   ← ไฟล์ที่สร้างใน Step 2 (ไม่ใช่ตัวจาก local!)
index.html             ← ทับของเดิม (เพิ่มปุ่ม AI + เร็วขึ้น)
login.php              ← ทับของเดิม (เพิ่ม mint token)
audio_proxy.php        ← ทับของเดิม (refactor ใช้ AudioDecoder ร่วมกับ pipeline)
```

**ไม่ต้องอัปโหลด**: `.env.example`, `migrations/` (ใช้ตอน import ผ่าน phpMyAdmin ไปแล้ว ไม่ต้องมีบน
host จริง), `.gitignore`, `DEPLOY.md`, `20260120-20260131/` (ข้อมูลทดสอบ local), `.playwright-mcp/`

หลัง upload เช็คว่า host อนุญาตให้ PHP สร้างโฟลเดอร์/เขียนไฟล์ใต้ `api/` ได้ (สำหรับ
`api/storage/audio_cache/` และ `api/logs/`) — ถ้า permission ไม่พอ ให้สร้างโฟลเดอร์เปล่า 2 อันนี้
เองผ่าน File Manager แล้วตั้ง permission 755/775

## Step 4 — Verify

1. `https://www.prima49.com/voicecall/api/index.php/health` → ต้องได้ JSON
   `{"ok":true,"status":"healthy",...}` (ถ้ายังเป็นหน้าเว็บหลักของบริษัท แปลว่าไฟล์ยังไม่ครบ/
   `.env` หา DB ไม่เจอ)
2. Login ที่ `https://www.prima49.com/voicecall/` ตามปกติ → กดปุ่ม "วิเคราะห์ด้วย AI" กับสายจริง 1
   สาย → ต้องเห็นผลลัพธ์ขึ้น (ถ้าพังจะมี error message ชัดเจน)
3. รัน sync ครั้งแรกด้วยมือก่อนตั้ง cron (เพื่อเช็คว่าไม่ error):
   ```
   php /domains/prima49.com/public_html/voicecall/api/cron/sync_gdrive_index.php
   ```

## Step 5 — Setup Cron (DirectAdmin → Cron Jobs)

```
*/15 * * * *  php /domains/prima49.com/public_html/voicecall/api/cron/sync_gdrive_index.php >> /domains/prima49.com/public_html/voicecall/api/logs/gdrive_sync.log 2>&1
```

ทุก 15 นาที — ปรับความถี่ได้ตามต้องการ (ยิ่งถี่ ยิ่งเห็นไฟล์ใหม่ไวขึ้น แต่ก็ยิงไปที่ Google Drive API
บ่อยขึ้นด้วย)

---

## 🔄 Update Workflow (ครั้งต่อไป)

ไม่มี build step — แก้ไฟล์ใน `api/`/`index.html`/`login.php`/`audio_proxy.php` แล้ว FTP
เฉพาะไฟล์ที่เปลี่ยนทับของเดิมได้เลย ถ้ามี migration ใหม่ ค่อย import เพิ่มผ่าน phpMyAdmin
(ไม่ต้อง re-import ของเก่า)

# วิธี Deploy voicecall ขึ้น Host ผ่าน FTP

คู่มือ**งาน deploy ประจำ** — แก้โค้ดเสร็จแล้วเอาขึ้น production
สำหรับการติดตั้งครั้งแรก (สร้าง DB, `.env`, cron) ดู [DEPLOY.md](DEPLOY.md) แทน

โปรเจกต์นี้ไม่มี build step (plain PHP + `index.html` ก้อนเดียว) — **อัปไฟล์ที่แก้ทับของเดิมได้เลย**

---

## 📋 พิกัด Production

| | |
|---|---|
| URL | https://www.prima49.com/voicecall |
| FTP host | `202.183.192.218` (หรือ `prima49.com`) port 21 |
| FTP user | `primacom` |
| Remote base | `/domains/prima49.com/public_html/voicecall/` |
| โครงไฟล์ | เหมือน repo เป๊ะ → `api/...`, `ui/...`, `index.html` ที่ราก |
| PHP | 8.0.30 fpm-fcgi, **ไม่มี OPcache** |
| DB | `primacom_voicelog` @ `202.183.192.218` (ไม่ใช่ `voicecall_ai` แบบ local) |

> ⚠️ **อย่าไปยุ่งกับ `/domains/voicecall.prima49.com/public_html/`** — โฟลเดอร์นั้นมีอยู่จริงแต่ข้างในเป็น
> หน้า placeholder ของ DirectAdmin ขนาด 403 ไบต์ ตัวเว็บจริงเสิร์ฟจาก path ในตารางด้านบน

---

## TL;DR — 4 คำสั่ง

รันใน **Git Bash** ที่ `C:\AppServ\www\voicecall`

```bash
# 1. เตรียม credentials (ทำครั้งเดียวต่อ session)
python - <<'PY'
import xml.etree.ElementTree as ET, base64, os
t = ET.parse(os.path.expandvars(r'%APPDATA%/FileZilla/recentservers.xml'))
for s in t.iter('Server'):
    if (s.findtext('Host') or '').strip() == '202.183.192.218' and (s.findtext('User') or '').strip() == 'primacom':
        pw = base64.b64decode(s.findtext('Pass')).decode()
        open(os.path.expandvars(r'%TEMP%/ftp.conf'), 'w').write('user = "primacom:%s"\n' % pw)
        print('ok'); break
PY

# 2. ดูว่าไฟล์ไหนเปลี่ยนบ้าง
git status --short

# 3. อัป (ใส่ path ไฟล์ที่เปลี่ยน — path บน host = path ใน repo)
B="ftp://202.183.192.218/domains/prima49.com/public_html/voicecall"
for f in api/Agents/UnifiedPipelineAgent.php index.html; do
  printf '%-45s ' "$f"
  curl -s -S --fail -K "$TEMP/ftp.conf" -T "$f" "$B/$f" && echo "OK ($(wc -c < "$f") bytes)"
done

# 4. เช็คว่าขึ้นจริง
curl -s -K "$TEMP/ftp.conf" "$B/api/Agents/"          # เทียบ byte size
curl -s https://www.prima49.com/voicecall/api/index.php/health
```

**ลบ `%TEMP%/ftp.conf` ทิ้งเมื่อเสร็จงาน** — และห้ามใส่รหัสผ่านลงบรรทัดคำสั่ง (มันติดอยู่ใน shell history)

---

## ขั้นตอนเต็ม

### Step 0 — เช็คก่อนอัป

```bash
git fetch                 # origin/main อาจนำหน้าอยู่ — merge ก่อน ไม่งั้นอัปทับงานคนอื่น
git status --short
git diff --stat
```

แล้ว**ดูวันที่ไฟล์บน prod เทียบกับที่จำได้ว่า deploy ล่าสุดเมื่อไหร่**:

```bash
curl -s -K "$TEMP/ftp.conf" "ftp://202.183.192.218/domains/prima49.com/public_html/voicecall/api/"
```

ถ้าเจอไฟล์ลงวันที่ **หลัง** deploy ครั้งล่าสุดที่เราทำ → มีคนอื่นอัปทับ (ดู [ข้อควรระวัง](#-ข้อควรระวัง-อ่านก่อนพลาด))
อย่าเพิ่งอัปจนกว่าจะรู้ว่าโดนอะไรไปบ้าง

### Step 1 — เตรียม credentials

รหัสผ่าน FTP เก็บเป็น base64 อยู่ใน `%APPDATA%\FileZilla\recentservers.xml` — ใช้สคริปต์ใน TL;DR
ดึงออกมาเขียนลง curl config ชั่วคราว

> 🚨 **ต้อง match ทั้ง host และ user** ห้าม match แค่ `<User>primacom</User>` อย่างเดียว
> เพราะในไฟล์มี `<Server>` ที่ user เป็น `primacom` อยู่หลายอัน (`27.254.96.235`,
> `202.183.192.218`, `prima49.com`) และ**รหัสผ่านคนละตัวกัน** ถ้าหยิบผิดอันจะได้
> `530 Access denied` ซึ่งอ่านดูเหมือน account หมดอายุ ทั้งที่แค่หยิบผิดแถว

### Step 2 — Backup ไฟล์ที่กำลังจะทับ (ถ้าเป็นการแก้ใหญ่)

```bash
mkdir -p /tmp/rollback && cd /tmp/rollback
for f in api/Agents/UnifiedPipelineAgent.php index.html; do
  mkdir -p "$(dirname "$f")"
  curl -s -K "$TEMP/ftp.conf" "$B/$f" -o "$f"
done
ls -la
```

ได้ของเดิมไว้ในมือแล้วค่อยอัปทับ — rollback = อัปไฟล์ในโฟลเดอร์นี้กลับไป

### Step 3 — Migration ต้องมาก่อนโค้ด ⚠️

**ถ้า diff แตะ SQL/ตาราง ให้รัน migration ก่อนอัป PHP เสมอ** ไม่งั้นโค้ดใหม่จะ INSERT ลงคอลัมน์
ที่ยังไม่มี แล้วพังทุกสาย

migration **ไม่ได้ไปทาง FTP** — ต่อ MySQL ตรงจากเครื่อง dev ได้เลย (เขียนสคริปต์ PDO สั้นๆ ชี้ไป
`primacom_voicelog` @ `202.183.192.218` ด้วย credential ชุด `ERP_DB_*` ใน `.env`)

จุดที่พลาดบ่อย:

- ไฟล์ใน `migrations/` ขึ้นต้นด้วย `USE voicecall_ai;` — **บน prod ชื่อฐานคือ `primacom_voicelog`**
  ต้องแก้/ตัดบรรทัดนั้นก่อนรัน
- เขียน ALTER แบบ "เช็ค `information_schema` ก่อนว่าคอลัมน์มีหรือยัง แล้วเติมเฉพาะที่ขาด"
  จะรันซ้ำได้ไม่พัง (MariaDB 10.6 บน host นี้ไม่รองรับ `ADD COLUMN IF NOT EXISTS` ในทุกกรณี)
- ตรวจผลด้วย `SHOW COLUMNS FROM <table>` ก่อนไป Step 4

### Step 4 — อัปไฟล์

```bash
B="ftp://202.183.192.218/domains/prima49.com/public_html/voicecall"
for f in <ไฟล์ที่เปลี่ยน...>; do
  printf '%-45s ' "$f"
  if curl -s -S --fail -K "$TEMP/ftp.conf" -T "$f" "$B/$f"; then
    echo "OK ($(wc -c < "$f") bytes)"
  else
    echo "FAILED"
  fi
done
```

- `--fail` สำคัญ — ไม่งั้น curl เงียบตอน upload ไม่สำเร็จ
- อัป**เฉพาะไฟล์ที่เปลี่ยน** ห้ามอัปทั้งโฟลเดอร์ `api/` (ดูข้อควรระวังข้อ 2)
- **ห้ามอัป**: `.env`, `api/config.php`, `service-account.json` — ของบน host ตั้งค่าคนละชุดกับ local
  (`api/config.php` ติด `.gitignore` ด้วย จึงไม่มีทางตรงกับ git อยู่แล้ว)
- ไฟล์ `bootstrap_*.php` ที่เขียนไว้ยิง migration ผ่าน HTTP: ถ้าจำเป็นต้องใช้ **ลบออกจาก prod ทันที
  ที่รันเสร็จ** — มันคือช่องรัน DDL ที่เปิด public ทิ้งไว้

  ```bash
  curl -s -K "$TEMP/ftp.conf" -Q "DELE /domains/prima49.com/public_html/voicecall/bootstrap_x.php" "$B/"
  ```

### Step 5 — Verify

```bash
# 1) byte size ตรงกับ local ไหม (ใช้กับไฟล์ PHP ที่อ่านผ่าน HTTP ไม่ได้)
curl -s -K "$TEMP/ftp.conf" "$B/api/Agents/"

# 2) ไฟล์ static เทียบ md5 ได้เลย — ตรงกัน = ขึ้นชัวร์
md5sum index.html
curl -s https://www.prima49.com/voicecall/index.html | md5sum

# 3) API ยังมีชีวิต
curl -s https://www.prima49.com/voicecall/api/index.php/health
# → {"ok":true,"status":"healthy",...}

# 4) มี fatal ใหม่ไหม (ดูวันที่ fatal_error.log ถ้าไม่ขยับ = ไม่มี error ใหม่)
curl -s -K "$TEMP/ftp.conf" "$B/api/logs/"
```

จากนั้น login เข้าหน้าเว็บจริง กดฟีเจอร์ที่เพิ่งแก้ 1 รอบ

### Step 6 — เก็บกวาด

```bash
rm -f "$TEMP/ftp.conf"
git add -A && git commit    # อย่าลืม commit ของที่ deploy ไปแล้ว
```

---

## 🚨 ข้อควรระวัง (อ่านก่อนพลาด)

**1. Deploy ไม่ได้มีผลทันทีทุกที่ — cron ยังกินโค้ดเก่าอีก ~20 นาที**

`process_pending` / `backlog_drain` รันได้ยาวถึง 1200 วินาที และ PHP compile ไฟล์ตอนเริ่ม process
รอบที่เริ่มไปก่อนเราอัปจะใช้โค้ดเก่าต่อจนจบ → error หลัง deploy ที่ line number เพี้ยนไปจากไฟล์จริง
คืออาการนี้ **ไม่ใช่ bytecode cache ค้าง** (host นี้ไม่ได้โหลด OPcache เลย `opcache_reset` ไม่มีด้วยซ้ำ)
**รอ 20 นาทีแล้วค่อยเช็คซ้ำ ก่อนจะสรุปว่าแก้ไม่ขึ้น**

**2. ห้ามอัปทั้งโฟลเดอร์ `api/`**

มี dev อีกคน (nokfee) ที่ deploy ด้วยการลาก `api/` ทั้งก้อนขึ้นจาก working copy ตัวเอง ซึ่ง branch
ไม่เคย merge — 23 ส.ค. 2026 ทำให้ prod ถอยกลับไปเป็นเวอร์ชัน 27 ก.ค. แบบเงียบๆ, `backlog_drain.php`
เรียกเมธอดที่หายไปกับการถอยครั้งนั้น และ cron ตายทุกรอบอยู่ ~20 ชั่วโมง

**อาการที่บอกว่าโดนเคสนี้:** ไฟล์บน prod ลงวันที่หลัง deploy ที่เราจำได้ + มีไฟล์ที่ local ไม่มี
**ทางกัน:** อัปทีละไฟล์ตาม `git status` และ `git fetch` ก่อนเสมอ

**3. เวลาบน host เป็น UTC**

`filemtime()` และ listing ของ FTP เป็น UTC แต่ log กับ row ใน DB เป็นเวลาไทย — ต่างกัน 7 ชั่วโมง
คือ timezone ไม่ใช่ upload พลาด

**4. อยากรู้ว่า server เห็นไฟล์เป็นยังไงจริงๆ**

ถ้าสงสัยว่าอัปขึ้นจริงไหม อัป `_deploy_check_<สุ่ม>.php` ที่ล็อกด้วย `?k=<token สุ่ม>` ให้มันรายงาน
`md5_file()` + `filemtime()` ของไฟล์ที่สงสัย ยิงครั้งเดียวแล้ว `DELE` ทิ้ง

**5. เช็คว่าไฟล์ PHP มีอยู่บน prod ไหม**

ยิง URL ตรงๆ — ไฟล์ที่มีจริงจะคืน body ว่าง (รันแล้วไม่ print อะไร), ไฟล์ที่ไม่มีจะตกไปที่ SPA shell
ของ `index.html`

**6. `deploy_sales_scoring.sh` ที่อยู่ใน repo ใช้ไม่ได้ตามที่เป็น**

`FTP_BASE` ในนั้นเป็น `/httpdocs` ซึ่งผิด (ที่ถูกคือ `/domains/prima49.com/public_html/voicecall`)
และ hardcode รหัสผ่านไว้ในไฟล์ — ใช้ขั้นตอนในเอกสารนี้แทน

---

## Rollback

```bash
cd /tmp/rollback
for f in $(find . -type f | sed 's|^\./||'); do
  curl -s -S --fail -K "$TEMP/ftp.conf" -T "$f" "$B/$f" && echo "reverted $f"
done
```

migration ที่เป็น `ADD COLUMN ... NULL` ไม่ต้อง rollback — คอลัมน์ที่โค้ดเก่าไม่รู้จักไม่รบกวนอะไร
ส่วน `DROP COLUMN` มีแต่จะทำข้อมูลหาย

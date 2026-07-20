# 📊 Analysis MiniERP

สรุปและวิเคราะห์ข้อมูลจากฐานข้อมูล **primacom_mini_erp** — ระบบ CRM/ERP หลักของ Prima49

---

## 🎯 จุดประสงค์

โปรเจกต์นี้ใช้สำหรับ **ดึง สรุป และวิเคราะห์ข้อมูล** จากฐานข้อมูล `primacom_mini_erp` เพื่อ:

1. **สรุปภาพรวมธุรกิจ** — ยอดขาย, จำนวนลูกค้า, สินค้า, การเงิน
2. **วิเคราะห์ประสิทธิภาพทีม** — ผลการทำงาน Telesale, การโทร, อัตราปิดการขาย
3. **ตรวจสอบข้อมูล** — ความถูกต้องของข้อมูล, ความสมบูรณ์ของ records
4. **รายงานเชิงบริหาร** — dashboard สำหรับผู้บริหารและหัวหน้าทีม

---

## 🔌 การเชื่อมต่อ Database

| รายการ | ค่า |
|--------|-----|
| **Host** | `202.183.192.218` |
| **Database** | `primacom_mini_erp` |
| **Username** | `primacom_bloguser` |
| **Password** | `pJnL53Wkhju2LaGPytw8` |
| **Port** | `3306` (default) |
| **Engine** | MariaDB 10.6.19 |
| **Charset** | `utf8mb4_unicode_ci` |

### วิธีเชื่อมต่อ

#### 1. ผ่าน MCP Server (ใน VS Code — แนะนำ)
MCP ตั้งค่าไว้แล้วใน `mcp_config.json` — แค่พิมพ์ถาม AI ได้เลย เช่น "ดูตาราง customers หน่อย"

#### 2. ผ่าน PHP Script
```php
<?php
$conn = new mysqli('202.183.192.218', 'primacom_bloguser', 'pJnL53Wkhju2LaGPytw8', 'primacom_mini_erp');
$conn->set_charset("utf8mb4");

$result = $conn->query("SELECT * FROM customers LIMIT 10");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}
$conn->close();
```
รันด้วย `php script.php`

#### 3. ผ่าน MySQL Client (Command Line)
```bash
mysql -h 202.183.192.218 -u primacom_bloguser -p primacom_mini_erp
```
แล้วใส่รหัส `pJnL53Wkhju2LaGPytw8`

#### 4. ผ่าน phpMyAdmin (Web UI)
เข้า https://www.prima49.com/phpmyadmin/ แล้ว login ด้วย:
- Username: `primacom_bloguser`
- Password: `pJnL53Wkhju2LaGPytw8`

---

## 📋 โครงสร้างฐานข้อมูล

ฐานข้อมูลประกอบด้วย **104 tables** และ **4 views** แบ่งตามหมวดหมู่ดังนี้:

### 👥 ลูกค้า (Customer Management)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `customers` | 180,910 | ข้อมูลลูกค้าหลัก (ชื่อ, เบอร์, grade, สถานะ) |
| `customer_logs` | 974,618 | ประวัติกิจกรรมลูกค้า (log ทุกการเปลี่ยนแปลง) |
| `customer_tags` | 49,059 | แท็กที่ติดให้ลูกค้า |
| `customer_address` | 146 | ที่อยู่จัดส่งลูกค้า |
| `customer_audit_log` | 14,039 | บันทึกการตรวจสอบลูกค้า |
| `customer_assign_check` | 162,645 | ตรวจสอบการมอบหมายลูกค้า |
| `customer_assignment_history` | 6,019 | ประวัติการมอบหมายลูกค้าให้ sales |
| `customer_blocks` | 21 | ลูกค้าที่ถูก block |
| `old_customers` | 2,961 | ข้อมูลลูกค้าเก่า (legacy) |

### 🛒 คำสั่งซื้อ (Order Management)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `orders` | 341,252 | คำสั่งซื้อหลัก (สถานะ, ยอดรวม, ผู้สร้าง) |
| `order_items` | 453,833 | รายการสินค้าในแต่ละ order |
| `order_boxes` | 299,640 | กล่องพัสดุแต่ละ order |
| `order_tracking_numbers` | 25,755 | เลขพัสดุสำหรับติดตาม |
| `order_item_allocations` | 38,719 | การจัดสรรสินค้าให้ order |
| `order_slips` | 310 | สลิปการชำระเงิน |
| `order_audit_log` | 1,265 | บันทึกการตรวจสอบคำสั่งซื้อ |
| `order_status_logs` | 7 | log การเปลี่ยนสถานะ |
| `order_sequences` | 18 | ลำดับเลข order |
| `order_tab_rules` | 84 | กฎการแสดง tab คำสั่งซื้อ |
| `order_box_collection_logs` | 0 | log การรวบรวมกล่อง |

### 📞 การโทร & นัดหมาย (Call & Appointments)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `call_history` | 258,337 | ประวัติการโทรทั้งหมด (เวลา, ผลลัพธ์, บันทึก) |
| `call_import_logs` | 219,951 | log การ import ข้อมูลโทร |
| `call_import_batches` | 15 | batch การ import |
| `appointments` | 82,831 | การนัดหมาย/follow-up |
| `activities` | 12,104 | กิจกรรมอื่นๆ |

### 🧺 ระบบตะกร้า (Basket Routing System)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `basket_transition_log` | 323,944 | log การย้ายลูกค้าระหว่างตะกร้า |
| `basket_return_log` | 53,909 | log การคืนลูกค้าเข้า pool |
| `basket_config` | 17 | ตั้งค่าตะกร้า (ชื่อ, เงื่อนไข, วัน) |
| `basket_return_config` | 6 | ตั้งค่าการคืน pool |

### 💰 การเงิน & COD

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `cod_records` | 15,795 | records COD (เก็บเงินปลายทาง) |
| `cod_documents` | 163 | เอกสาร COD |
| `statement_logs` | 2,814 | log รายการ bank statement |
| `statement_batchs` | 480 | batch การ import statement |
| `statement_reconcile_logs` | 17,897 | log การตรวจสอบยอดเงิน |
| `statement_reconcile_batches` | 2,213 | batch การ reconcile |
| `bank_account` | 14 | บัญชีธนาคาร |
| `debt_collection` | 238 | ติดตามหนี้ |
| `debt_collection_images` | 156 | รูปภาพหลักฐานหนี้ |

### 📦 สินค้า & คลัง (Products & Inventory)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `products` | 122 | รายการสินค้า |
| `product_lots` | 7 | lot สินค้า |
| `promotions` | 11 | โปรโมชั่น |
| `promotion_items` | 15 | สินค้าในโปรโมชั่น |
| `warehouses` | 7 | คลังสินค้า |
| `warehouse_stocks` | 15 | สต็อกในคลัง |
| `inv2_stock` | 1 | สต็อก V2 |
| `inv2_stock_orders` | 1 | ใบสั่งซื้อสต็อก V2 |
| `inv2_stock_order_items` | 1 | รายการใบสั่งซื้อสต็อก |
| `inv2_movements` | 1 | การเคลื่อนไหวสต็อก |
| `inv2_receive_documents` | 1 | เอกสารรับสินค้า |
| `inv2_receive_items` | 1 | รายการรับสินค้า |

### 👤 ผู้ใช้งาน & สิทธิ์ (Users & Permissions)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `users` | 173 | ผู้ใช้งานระบบ (พนักงาน) |
| `roles` | 10 | กลุ่มสิทธิ์ (Admin, Telesale, Supervisor ฯลฯ) |
| `role_permissions` | 17 | สิทธิ์ของแต่ละ role |
| `user_permission_overrides` | 0 | override สิทธิ์เฉพาะคน |
| `user_tokens` | 7,296 | token สำหรับ authentication |
| `user_login_history` | 3,550 | ประวัติการเข้าสู่ระบบ |
| `user_tags` | 123 | แท็กผู้ใช้ |
| `user_daily_attendance` | 3,638 | บันทึกการทำงานรายวัน |
| `user_pancake_mapping` | 0 | mapping กับ Pancake |
| `companies` | 5 | บริษัทในระบบ |

### 📢 การตลาด & Pages (Marketing)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `pages` | 230 | เพจ/ช่องทางขาย |
| `page_user` | 92 | ผู้ดูแลเพจ |
| `page_list_user` | 1,169 | รายชื่อคนในเพจ |
| `page_stats_log` | 123 | สถิติเพจ |
| `page_stats_batch` | 1 | batch สถิติเพจ |
| `platforms` | 17 | แพลตฟอร์มขาย |
| `marketing_ads_log` | 3,181 | log โฆษณา |
| `marketing_user_page` | 59 | mapping user-เพจโฆษณา |
| `marketing_user_ads_group` | 37 | กลุ่มโฆษณาของ user |
| `marketing_product_ads_log` | 3 | log โฆษณาสินค้า |
| `marketing_user_product` | 1 | สินค้าโฆษณาของ user |
| `ad_spend` | 0 | ค่าใช้จ่ายโฆษณา |
| `sales_targets` | 84 | เป้ายอดขาย |

### 🏷️ แท็ก & Reference Data

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `tags` | 173 | แท็กทั้งหมดในระบบ |
| `address_geographies` | 6 | ภูมิภาค (เหนือ, กลาง, อีสาน ฯลฯ) |
| `address_provinces` | 77 | จังหวัด |
| `address_districts` | 930 | อำเภอ |
| `address_sub_districts` | 7,652 | ตำบล |

### 📤 Export & OneCall

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `exports` | 333 | การ export ข้อมูล |
| `export_order_items` | 836 | รายการสินค้าที่ export |
| `export_templates` | 2 | template การ export |
| `export_template_columns` | 81 | คอลัมน์ใน template |
| `export_template_defaults` | 5 | ค่า default template |
| `google_sheet_shipping` | 7,562 | ข้อมูล shipping จาก Google Sheet |
| `tracking_import_logs` | 4,244 | log import เลขพัสดุ |
| `onecall_log` | 42,051 | log ระบบโทร OneCall |
| `onecall_batch` | 13 | batch OneCall |

### 🔔 แจ้งเตือน & ระบบ

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `notifications` | 5 | การแจ้งเตือน |
| `notification_roles` | 6 | แจ้งเตือนตาม role |
| `notification_users` | 2 | แจ้งเตือนรายคน |
| `notification_settings` | 3 | ตั้งค่าแจ้งเตือน |
| `notification_read_status` | 0 | สถานะอ่านแจ้งเตือน |
| `env` | 19 | ค่า config ของระบบ |
| `upsell_round_robin` | 1 | round-robin สำหรับ upsell |
| `commission_periods` | 1 | รอบคอมมิชชั่น |
| `commission_records` | 0 | records คอมมิชชั่น |
| `commission_order_lines` | 0 | รายการคอมมิชชั่น |
| `tmp_assignment_periods` | 0 | ข้อมูลชั่วคราว |

### 👁️ Views

| View | คำอธิบาย |
|------|----------|
| `v_customer_buckets` | มุมมองรวมลูกค้าตามตะกร้า |
| `v_order_required_stock` | สต็อกที่ต้องใช้ตาม order |
| `v_telesale_call_overview_monthly` | สรุปการโทร telesale รายเดือน |
| `v_user_daily_attendance` | สรุปการทำงานรายวัน |
| `v_user_daily_kpis` | KPI รายวันของ user |

---

## 🔑 ตารางสำคัญสำหรับการวิเคราะห์

### ข้อมูลระดับ Top (ตาม row count)

| # | Table | Rows | ใช้วิเคราะห์ |
|---|-------|-----:|-------------|
| 1 | `customer_logs` | 974K | พฤติกรรมลูกค้า, timeline |
| 2 | `order_items` | 454K | ยอดขาย, สินค้าขายดี, รายได้ |
| 3 | `orders` | 341K | ภาพรวม order, สถานะ, ช่องทาง |
| 4 | `basket_transition_log` | 324K | การไหลของ lead, ประสิทธิภาพ routing |
| 5 | `call_history` | 258K | ประสิทธิภาพการโทร, AHT |
| 6 | `customers` | 181K | ฐานลูกค้า, segmentation, grading |

### Joins ที่ใช้บ่อย

```sql
-- ยอดขายตาม sales
orders o
  JOIN order_items oi ON oi.parent_order_id = o.id
  JOIN users u ON u.id = oi.creator_id
  JOIN products p ON p.id = oi.product_id

-- ลูกค้าตามภูมิภาค
customers c
  JOIN address_provinces ap ON c.province = ap.name_th
  JOIN address_geographies ag ON ap.geography_id = ag.id

-- ประสิทธิภาพ telesale
users u
  JOIN call_history ch ON ch.user_id = u.id
  JOIN user_daily_attendance uda ON uda.user_id = u.id
```

### สูตรคำนวณรายได้มาตรฐาน

```sql
-- Net Revenue (ยอดจริง)
SUM(COALESCE(oi.net_total, oi.quantity * oi.price_per_unit))

-- เงื่อนไขกรอง
WHERE o.status != 'Cancelled'
  AND oi.is_freebie = 0

-- สายที่ได้คุย (Answered Call)
WHERE ch.duration >= 40
```

---

## 📝 หมายเหตุ

- ข้อมูลเป็น **production data** — ระวังเรื่องความปลอดภัยและ privacy
- ใช้ `utf8mb4` charset สำหรับข้อมูลภาษาไทย
- Row counts เป็นค่าประมาณจาก `information_schema` (ณ มี.ค. 2026)
- การ query ควรใช้ `LIMIT` เพื่อป้องกัน timeout กับตารางใหญ่

---

# 📊 AI Voice Intelligence Platform (primacom_voicelog)

ฐานข้อมูล output ของ 6-agent AI pipeline (transcribe → summarize → extract → compliance →
index → assistant) ของโปรเจกต์ `voicecall` — **แยกจาก `primacom_mini_erp` คนละฐานข้อมูล**
แต่อยู่บนโฮสต์เดียวกัน

## 🔌 การเชื่อมต่อ Database

| รายการ | ค่า |
|--------|-----|
| **Host** | `202.183.192.218` |
| **Database** | `primacom_voicelog` |
| **Username** | `primacom_bloguser` (เดียวกับ mini_erp) |
| **Password** | `pJnL53Wkhju2LaGPytw8` (เดียวกับ mini_erp) |
| **Port** | `3306` (default) |

> ⚠️ **เคยเข้าใจผิดว่าฐานนี้ต่อได้แค่จาก production server เอง (localhost-only)** เพราะตอน
> deploy ครั้งแรกต่อ external ไม่ติด เลยใช้วิธีอัปโหลดสคริปต์ผ่าน FTP แล้วยิง trigger ผ่าน
> HTTPS แทน — **ที่จริงเชื่อมต่อตรงจากเครื่อง dev ได้เลย เหมือน mini_erp ทุกอย่าง** (ทดสอบแล้ว
> 2026-06-28) ใช้ host/user/password บรรทัดบนนี้ต่อตรงได้ทันที ไม่ต้องผ่าน bootstrap script
> อีกต่อไป (ยกเว้นกรณีต้องรันโค้ด PHP จริงๆบน production เช่นทดสอบ `config.php`/relative path)

### วิธีเชื่อมต่อ (เหมือน mini_erp)

```bash
mysql -h 202.183.192.218 -u primacom_bloguser -p primacom_voicelog
```

```php
<?php
$conn = new mysqli('202.183.192.218', 'primacom_bloguser', 'pJnL53Wkhju2LaGPytw8', 'primacom_voicelog');
$conn->set_charset("utf8mb4");
```

## 📋 โครงสร้างฐานข้อมูล (ณ 2026-06-28)

| Table | Rows | คำอธิบาย |
|-------|-----:|----------|
| `conversations` | 7 | สายที่ลงทะเบียนแล้ว (audio_ref, caller/receiver, สถานะ, จับคู่ ERP) |
| `transcripts` | 7 | ข้อความถอดเสียงเต็ม (1 ต่อ 1 conversation) |
| `transcript_segments` | 101 | บทพูดแยกตาม speaker_1/speaker_2 + role (employee/customer) |
| `speakers` | 12 | role ของแต่ละ speaker_label ต่อสาย |
| `summaries` | 7 | สรุปบทสนทนา, sentiment, keywords |
| `keywords` | 37 | คำสำคัญที่แยกออกมา |
| `extracted_entities` | 7 | ลูกค้า/พนักงาน/สินค้า/ราคาที่แกะได้ + จับคู่กับ ERP catalog/order |
| `action_items` | 12 | สิ่งที่ต้องทำต่อจากการคุย |
| `conversation_tags` | 16 | แท็กของแต่ละสาย |
| `compliance_rules` | 3 | กฎ compliance ที่ตั้งไว้ |
| `compliance_reports` | 7 | ผลตรวจ compliance ต่อสาย |
| `violations` | 5 | รายการที่ผิดกฎ |
| `fraud_checks` | 0 | ผลตรวจทุจริต: ช่องทางรับเงินที่พูดในสาย เทียบกับ `mini_erp.bank_account` (สร้าง 2026-07-20) |
| `knowledge_chunks` | 10 | chunk สำหรับ RAG assistant |
| `assistant_queries` | 0 | ประวัติคำถามที่ถาม AI assistant |
| `gdrive_file_index` | 133,508 | index ไฟล์เสียงจาก Google Drive (ใช้แทนการ scan สดทุกครั้ง) |
| `gdrive_sync_runs` | 2 | ประวัติการ sync index |
| `api_tokens` | 29 | token สำหรับ auth เข้า API ของ voicecall |
| `audit_log` | 8 | log การกระทำสำคัญ (register, process, ฯลฯ) |

## 🔑 ข้อควรรู้

- ⏰ **เวลาในไฟล์เสียงมี 2 มาตรฐาน** — ระบบ OneCall (call_code เป็นตัวเลข 9 หลัก เช่น `107988980`
  ใช้ตั้งแต่ 2026-06-12) เขียนชื่อไฟล์เป็น **UTC** ส่วน PBX เดิม (call_code ตัวอักษร เช่น `OYIP`)
  และไฟล์ `myrecordings_*` เป็นเวลาไทยอยู่แล้ว — `GdriveIndexer::parseFilename()` แปลง UTC→ไทย
  ให้ตั้งแต่ตอน sync และ `migrations/008_fix_onecall_timezone.sql` แก้ข้อมูลเก่าไปแล้ว
  (มี `tz_normalized` กันรันซ้ำ) **ข้อมูลใน DB ตอนนี้เป็นเวลาไทยทั้งหมด** ห้ามบวก 7 ซ้ำอีก
- 🔗 **จับคู่ไฟล์เสียงกับผลการขายใน ERP ได้** ผ่าน `ErpCallOutcomeService`: เบอร์ → `customers` →
  หา `call_history` ที่ใกล้ที่สุดในช่วง [เริ่มสาย − 30 นาที, จบสาย + 30 นาที] (พนักงานคีย์ CRM
  หลังวางสาย ค่ามัธยฐาน +3~4 นาที **ต้องเผื่อความยาวสายด้วย** ไม่งั้นสายยาวจะจับไม่ติด) —
  `call_history.result = 'ขายได้'` คือปิดการขายได้ ครอบคลุมราว 40-70% ของสาย (ที่เหลือคือสายที่
  พนักงานไม่ได้คีย์ CRM ซึ่งเป็นสัญญาณในตัวมันเอง) พร้อมดึง `orders` ในช่วง 5 วันหลังสาย
  (`order_status` ∈ Cancelled/Returned/BadDebt = ตีกลับ)
- `conversations.status`: `pending` → `transcribing` → ... → `completed`/`failed`
- เบอร์โทรใน `conversations` เก็บแบบ E.164 (`+66...`) ส่วน `primacom_mini_erp.customers.phone`
  เก็บแบบ local (`0...`) และ `primacom_mini_erp.users.phone` **เก็บผสมทั้ง 2 แบบ** — ดู
  `ErpLookupService::candidateFormats()` ในโค้ดถ้าต้อง join ข้ามฐานด้วยเบอร์โทร
- ไม่มี FK ข้ามฐานข้อมูล (mini_erp อยู่คนละ schema) — `erp_customer_id`/`erp_employee_id` เป็น
  plain int อ้างอิงไว้เฉยๆ ต้อง join ที่ application layer
- การประมวลผล AI จริง (transcribe/summarize/ฯลฯ) มีต้นทุนจริงต่อสาย (OpenRouter) — อย่ารัน
  `process` ซ้ำโดยไม่ตั้งใจ ดู `conversations.status` ก่อนว่า `completed` แล้วหรือยัง
- `fraud_checks`: LLM (UnifiedPipelineAgent `fraud_signals`) ทำหน้าที่แค่ **แกะ** ช่องทางรับเงิน
  ที่พูดในสาย ส่วน **คำตัดสิน** (ตรง/ไม่ตรงบัญชีบริษัท) คำนวณแบบ deterministic ใน
  `FraudCheckService` เทียบกับ `primacom_mini_erp.bank_account` — ทุก row เป็น "รายการให้คน
  ยืนยัน" (`review_status`) ไม่ใช่ข้อกล่าวหาอัตโนมัติ ดูผ่าน `ui/fraud_dashboard.html` หรือ
  API `GET /api/index.php/fraud`
- `fraud_checks.check_type` มี 3 ประเภท: `payment_channel` (ตรวจตอน pipeline รัน),
  `missing_order` + `price_mismatch` (ตรวจย้อนหลังโดย `api/cron/fraud_order_check.php` —
  ต้องรอ GRACE_DAYS=2 วันหลังโทร เพราะ order อาจถูกคีย์ทีหลัง; เทียบกับ
  `primacom_mini_erp.orders` ผ่าน `OrderCrossCheckService`) —
  `extracted_entities.sale_outcome` (closed_won/follow_up/declined/not_sales_call) เป็นตัวบอก
  ว่าสายไหน "ปิดการขายได้" ควรมี order ตามมา

#!/usr/bin/env bash
# เดินงาน voicecall แบบต่อเนื่อง — เรียก cron endpoint ของ prima49.com ตามรอบ
#
# ทำไมรันจากที่นี่ ไม่ใช่ cron ของ DirectAdmin: เครื่องนี้เปิดตลอดอยู่แล้ว มี root ให้ดู log ได้จริง
# และอยู่คนละ IP กับ prima49 — Google Drive บล็อก IP ที่ดาวน์โหลดรัวเกินไป การกระจายภาระออกมาจึงช่วย
#
# ทีละน้อยแต่บ่อย เป็นเรื่องตั้งใจ: SAMPLING_MAX_CALLS_PER_RUN=3 ทุก 20 นาที = ~216 สาย/วัน
# ต่ำกว่าเพดาน 800 มาก เว้นที่ให้ทั้งโควตา MiniMax, CPU สองคอร์ที่แชร์กับ Rocket.Chat/Jitsi
# และอัตราการดาวน์โหลดที่ Drive ยอมรับ
set -uo pipefail

BASE="https://www.prima49.com/voicecall/api/cron"
KEY_FILE="/opt/voicecall/.cron_key"
LOG="/opt/voicecall/worker.log"

[ -r "$KEY_FILE" ] || { echo "$(date -Is) ไม่พบ $KEY_FILE" >> "$LOG"; exit 1; }
KEY="$(tr -d "\r\n" < "$KEY_FILE")"

run() {
  local name="$1" timeout="$2"
  local out code
  out=$(curl -sS -m "$timeout" -w "\n__HTTP:%{http_code}" "${BASE}/${name}.php?key=${KEY}" 2>&1)
  code=$(printf "%s" "$out" | tail -1 | sed "s/__HTTP://")
  # เก็บเฉพาะบรรทัดสรุป — log เต็มของทุกสายจะโตเร็วเกินไป
  printf "%s [%s] http=%s %s\n" "$(date -Is)" "$name" "${code:-timeout}" \
    "$(printf "%s" "$out" | grep -viE "^__HTTP|^$" | tail -2 | tr "\n" " | ")" >> "$LOG"
}

# ลำดับสำคัญ:
#  1. ดัชนี Drive ต้องใหม่ก่อน ไม่งั้นตัวไล่งานจะไม่เห็นสายของวันนี้
#  2. ปลดสายที่ค้างกลางทางก่อน ไม่งั้นมันจะไม่กลับมาอีกเลย
#  3. เก็บสายที่รออยู่ให้หมดก่อน แล้วค่อยไปเอางานใหม่จาก backlog
#  4. backlog_drain ไล่จากใหม่ไปเก่า จนกว่าทุกไฟล์จะมีผลลัพธ์
#
# smart_sampling ไม่ได้เรียกแล้ว: มันสุ่มจากกรอบ 7 วัน ซึ่งทิ้งของเก่าถาวร
# backlog_drain ครอบคลุมงานเดียวกันและจบได้จริง
# process_pending / backlog_drain now stop claiming new work once their own internal
# RUN_TIME_BUDGET_SECONDS (1200s) is spent, so 1800s here is margin for whatever single call was
# already in flight when that budget ran out, not the normal case - a run that behaves should
# return well under this.
run sync_gdrive_index 900
run reap_stale          120
run process_pending    1800
run backlog_drain      1800

# ตัด log ไม่ให้โตไม่จำกัด
tail -n 2000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"

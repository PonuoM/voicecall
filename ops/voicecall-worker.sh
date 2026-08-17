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

# ลำดับสำคัญ: ดัชนี Drive ต้องใหม่ก่อน ไม่งั้นตัวเลือกสายจะไม่เห็นสายของวันนี้
run sync_gdrive_index 900
run smart_sampling     1500
run process_pending    1500

# ตัด log ไม่ให้โตไม่จำกัด
tail -n 2000 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"

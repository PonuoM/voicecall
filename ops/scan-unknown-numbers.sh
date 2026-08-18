#!/usr/bin/env bash
# สแกนเบอร์นอกระบบทุก 6 ชม. — ดูรายละเอียดที่ api/cron/scan_unknown_numbers.php
set -uo pipefail
KEY_FILE="/opt/voicecall/.cron_key"
LOG="/opt/voicecall/scan_unknown.log"
[ -r "$KEY_FILE" ] || { echo "$(date -Is) ไม่พบ $KEY_FILE" >> "$LOG"; exit 1; }
KEY="$(tr -d "\r\n" < "$KEY_FILE")"
out=$(curl -sS -m 300 "https://www.prima49.com/voicecall/api/cron/scan_unknown_numbers.php?key=${KEY}" 2>&1)
printf "%s %s\n" "$(date -Is)" "$(printf "%s" "$out" | tr "\n" " | ")" >> "$LOG"
tail -n 500 "$LOG" > "${LOG}.tmp" && mv "${LOG}.tmp" "$LOG"

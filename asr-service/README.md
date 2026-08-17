# Typhoon ASR service — ถอดเสียงภาษาไทยบน VPS ตัวเอง

แทนที่ขั้น STT ที่เดิมยิงไฟล์เสียงเข้า `gemini-2.5-flash` ผ่าน OpenRouter ด้วยโมเดลที่รันเอง
เปิดเป็น endpoint หน้าตาเดียวกับ OpenAI (`POST /v1/audio/transcriptions`) เพื่อให้ฝั่ง PHP
เปลี่ยนแค่ base URL ไม่ต้องแก้ตรรกะ

## ทำไมต้องเปลี่ยน

วัดจากไฟล์เสียง production 46 ไฟล์:

| | Gemini (ของเดิม) | Typhoon |
|---|---|---|
| เสียงเงียบ 8 ไฟล์ | แต่งบทสนทนาขึ้นมา สูงสุด **162,905 ตัวอักษร** จากเสียง 183 วินาที | **0 ตัวอักษร ทั้ง 8 ไฟล์** |
| CER บนเสียงดี (มัธยฐาน) | — (ใช้เป็นฐานเทียบ) | 0.392 — ดีกว่า Whisper large-v3 (0.679) และตัว fine-tune ไทยทั้งสองตัว |
| ปริมาณข้อความที่จับได้ | 100% (ฐาน) | 96.3% |
| ความเร็ว (CPU 2 core) | — | **9.13x realtime** |
| ค่าใช้จ่าย | ตามจำนวน token | ค่าเครื่องอย่างเดียว |

Typhoon เป็น FastConformer-Transducer ผลลัพธ์ผูกกับแกนเวลาของเสียง จึง**วนซ้ำไม่จบแบบ Gemini
ไม่ได้ในเชิงสถาปัตยกรรม** ไม่ใช่แค่ "โอกาสน้อย"

## ความต้องการของเครื่อง

ทดสอบบน 2 vCPU / 8 GB (Hostinger KVM 2) — งานจริง 40.9 ชม.เสียง/วัน หักเสียงเงียบ 30% เหลือ
~28.6 ชม. ที่ 9.13x realtime ใช้เวลาประมวลผล **~3.1 ชม./วัน คิดเป็น 13% ของ CPU**

## ติดตั้ง

```bash
ssh root@187.77.127.28

git clone <repo> /opt/voicecall && cd /opt/voicecall/asr-service
# หรือ scp เฉพาะโฟลเดอร์ asr-service/ ขึ้นไป

printf 'ASR_API_KEY=%s\n' "$(openssl rand -hex 32)" > .env
chmod 600 .env

docker compose up -d --build     # build แรกกินเวลา ~10-15 นาที (โหลด torch + NeMo + checkpoint)
docker compose logs -f           # รอจนขึ้น "model ready"
```

## ตรวจว่าใช้ได้

```bash
curl -s localhost:8000/health
# {"ok":true,"model":"scb10x/typhoon-asr-realtime","loaded":true,"threads":2}

source .env
curl -s -X POST localhost:8000/v1/audio/transcriptions \
  -H "Authorization: Bearer $ASR_API_KEY" \
  -F file=@ตัวอย่าง.wav -F language=th
# {"text":"...","duration":201.0,"realtime_factor":9.1}
```

`realtime_factor` ในทุก response คือตัวเลขที่บอกว่าเครื่องยังตามงานทันไหม ถ้าตกลงไปใกล้ 1
แปลว่าเริ่มตึง

## เรื่องความปลอดภัย

พอร์ตผูกกับ `127.0.0.1` เท่านั้น **ห้ามเปิดออก public โดยไม่มี TLS** เพราะ API key จะวิ่งเป็น
ข้อความเปล่าบนอินเทอร์เน็ต ทางที่ทำได้:

- **SSH tunnel** จากเครื่องที่เรียกใช้ — ง่ายที่สุด ไม่ต้องตั้ง cert
- **Caddy / nginx + Let's Encrypt** วางหน้า ถ้าจะให้ prima49.com เรียกตรง

## ต่อเข้ากับ pipeline เดิม

ยังไม่ได้ทำ — ต้องแก้ `api/Services/OpenRouterClient.php` ที่ตอนนี้ใช้ `OPENROUTER_BASE_URL`
ตัวเดียวคุมทั้ง chat / STT / embeddings ให้แยกเป็น `STT_BASE_URL` + `STT_API_KEY` เพื่อให้
`SttAgent` ชี้มาที่ service นี้ ส่วน chat ชี้ไป MiniMax

Guard สองชั้นใน `SttAgent` (`AudioQuality::rejectionReason` / `loopReason`) **ยังต้องอยู่ต่อ**
แม้ Typhoon จะวนไม่ได้ — ชั้นแรกยังตัดเสียงเงียบ 30% ทิ้งก่อนเสียเวลา CPU และชั้นที่สองยังจับ
การซ้ำแบบสั้นได้ (ทดสอบเจอ "ตําบล" ซ้ำ 14 ครั้งใน conv=14)

"""
Typhoon ASR as an OpenAI-compatible transcription endpoint.

Deliberately shaped like OpenAI's /v1/audio/transcriptions rather than something bespoke: the PHP
pipeline already speaks that shape, so pointing SttAgent at this service is a base-URL change
instead of a rewrite.

Why this model. Measured against 46 production recordings, Gemini answered silent audio by
inventing conversation — 162,905 characters from 183 seconds at -74 dBFS, 84% of every transcript
character in the database traceable to eleven such runs. Whisper large-v3 and two Thai fine-tunes
each did the same thing differently on the same files. Typhoon is a FastConformer-Transducer: its
output is bound to the input's time axis, so it has no mechanism for that failure. On the eight
silent recordings it returned zero characters, every time. On good audio it also beat all three
Whisper variants (median CER 0.392 vs 0.597) while capturing 96% of the text volume, at 9x realtime
on two CPU cores.

The service holds one model instance for its whole life. The `typhoon_asr.transcribe()` convenience
wrapper reloads the checkpoint on every call, which costs several seconds per request and would
dominate the runtime of a 40-second phone call.
"""

from __future__ import annotations

import logging
import os
import tempfile
import threading
import time
from contextlib import asynccontextmanager
from pathlib import Path

import librosa
import soundfile as sf
import torch
from fastapi import FastAPI, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse

MODEL_NAME = os.getenv("ASR_MODEL", "scb10x/typhoon-asr-realtime")
API_KEY = os.getenv("ASR_API_KEY", "")
TARGET_SAMPLE_RATE = 16000
# Recordings arrive as 8 kHz telephony; the encoder expects 16 kHz.
MAX_UPLOAD_BYTES = int(os.getenv("ASR_MAX_UPLOAD_BYTES", str(200 * 1024 * 1024)))

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("typhoon-asr")

_model = None
# NeMo's transcribe() is not safe to call concurrently on one instance, and the box this runs on has
# two cores anyway — serialising requests here is both correct and the fastest arrangement, since
# two parallel transcriptions would only contend for the same threads.
_model_lock = threading.Lock()


def load_model():
    global _model
    if _model is None:
        started = time.time()
        log.info("loading %s ...", MODEL_NAME)
        import nemo.collections.asr as nemo_asr

        _model = nemo_asr.models.ASRModel.from_pretrained(MODEL_NAME, map_location="cpu")
        _model.eval()
        log.info("model ready in %.1fs", time.time() - started)
    return _model


@asynccontextmanager
async def lifespan(_: FastAPI):
    torch.set_num_threads(int(os.getenv("ASR_THREADS", str(os.cpu_count() or 2))))
    load_model()
    yield


app = FastAPI(title="Typhoon ASR", lifespan=lifespan)


@app.middleware("http")
async def require_api_key(request: Request, call_next):
    """
    The service holds no secrets but it does hold CPU: left open, anyone could point a firehose of
    audio at it. /health stays public so an uptime check does not need the key.
    """
    if request.url.path != "/health" and API_KEY:
        presented = request.headers.get("authorization", "")
        if presented != f"Bearer {API_KEY}":
            return JSONResponse({"error": {"message": "Unauthorized"}}, status_code=401)
    return await call_next(request)


@app.get("/health")
def health():
    return {"ok": True, "model": MODEL_NAME, "loaded": _model is not None,
            "threads": torch.get_num_threads()}


@app.post("/v1/audio/transcriptions")
async def transcriptions(
    file: UploadFile = File(...),
    model: str = Form(default=MODEL_NAME),
    language: str = Form(default="th"),
    response_format: str = Form(default="json"),
):
    payload = await file.read()
    if not payload:
        raise HTTPException(status_code=400, detail="empty upload")
    if len(payload) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="file too large")

    with tempfile.TemporaryDirectory() as workdir:
        source = Path(workdir) / (file.filename or "audio.wav")
        source.write_bytes(payload)

        try:
            samples, rate = librosa.load(str(source), sr=None, mono=True)
        except Exception as exc:
            raise HTTPException(status_code=400, detail=f"cannot decode audio: {exc}") from exc

        duration = len(samples) / rate if rate else 0.0
        if duration <= 0:
            raise HTTPException(status_code=400, detail="audio contains no samples")

        if rate != TARGET_SAMPLE_RATE:
            samples = librosa.resample(samples, orig_sr=rate, target_sr=TARGET_SAMPLE_RATE)

        prepared = Path(workdir) / "prepared.wav"
        sf.write(str(prepared), samples, TARGET_SAMPLE_RATE)

        started = time.time()
        with _model_lock:
            outputs = load_model().transcribe(audio=[str(prepared)])
        elapsed = max(time.time() - started, 1e-6)

    text = _first_text(outputs)
    log.info("%s | %.0fs audio in %.1fs (%.1fx realtime) -> %d chars",
             file.filename, duration, elapsed, duration / elapsed, len(text))

    if response_format == "text":
        return JSONResponse(content=text, media_type="text/plain")

    return {
        "text": text,
        "language": language,
        "duration": round(duration, 2),
        # Not part of OpenAI's contract, but the pipeline logs it and it is the number that tells
        # you whether the box is keeping up without having to time requests from the outside.
        "realtime_factor": round(duration / elapsed, 2),
    }


def _first_text(outputs) -> str:
    """
    NeMo returns a list whose entries are either strings or Hypothesis objects depending on version
    and decoding config. Unwrap defensively — str()-ing a Hypothesis yields a tensor repr that would
    sail through as a plausible-looking transcript.
    """
    if outputs is None:
        return ""
    if isinstance(outputs, str):
        return outputs.strip()
    if isinstance(outputs, (list, tuple)):
        return _first_text(outputs[0]) if outputs else ""
    inner = getattr(outputs, "text", None)
    if isinstance(inner, str):
        return inner.strip()
    raise TypeError(f"unexpected transcription result: {type(outputs).__name__}")

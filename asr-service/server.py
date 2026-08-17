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
import numpy as np
import soundfile as sf
import torch
from fastapi import FastAPI, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse

MODEL_NAME = os.getenv("ASR_MODEL", "scb10x/typhoon-asr-realtime")
API_KEY = os.getenv("ASR_API_KEY", "")
TARGET_SAMPLE_RATE = 16000
# Recordings arrive as 8 kHz telephony; the encoder expects 16 kHz.
MAX_UPLOAD_BYTES = int(os.getenv("ASR_MAX_UPLOAD_BYTES", str(200 * 1024 * 1024)))

# NeMo decodes a recording in one pass and its memory grows with the length of that pass. A
# 201-second call peaked at 2.0 GB and was killed at a 2 GB cgroup limit; raising the limit to 3.5 GB
# only moved the wall, and a 301-second call was killed at exactly 3.5 GB. The corpus reaches 7,204
# seconds, with 4,678 calls over ten minutes, so no ceiling survives contact with it.
#
# Chunking makes peak memory a property of the window rather than the call. 30 seconds is not an
# arbitrary pick: this checkpoint's own training config caps segments at max_duration 30.0, so a
# three-minute file was already outside what it was built to swallow whole.
CHUNK_SECONDS = float(os.getenv("ASR_CHUNK_SECONDS", "30"))
# How far either side of a nominal boundary to hunt for a quiet moment to cut on, so a split lands
# between words instead of through one.
CHUNK_SEARCH_SECONDS = float(os.getenv("ASR_CHUNK_SEARCH_SECONDS", "2"))

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

        bounds = _chunk_bounds(samples, TARGET_SAMPLE_RATE)
        started = time.time()
        pieces = []
        with _model_lock:
            model = load_model()
            for index, (begin, end) in enumerate(bounds):
                piece_path = Path(workdir) / f"chunk_{index:04d}.wav"
                sf.write(str(piece_path), samples[begin:end], TARGET_SAMPLE_RATE)
                pieces.append(_first_text(model.transcribe(audio=[str(piece_path)])))
                # Dropped as we go rather than at the end: keeping every chunk on disk for a
                # two-hour call is gigabytes of scratch space for no reason.
                piece_path.unlink(missing_ok=True)
        elapsed = max(time.time() - started, 1e-6)

    # Joined without a separator. Thai is written unspaced and the model emits it that way, so
    # inserting spaces at chunk boundaries would leave a trail of artificial word breaks that
    # nothing downstream expects — ThaiNumerals in particular reads digit words as one run.
    text = "".join(p for p in pieces if p).strip()
    log.info("%s | %.0fs audio in %.1fs (%.1fx realtime) over %d chunk(s) -> %d chars",
             file.filename, duration, elapsed, duration / elapsed, len(bounds), len(text))

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


def _chunk_bounds(samples, rate: int) -> list:
    """
    Sample ranges to transcribe, one per chunk.

    Boundaries start at fixed CHUNK_SECONDS intervals and then slide to the quietest 20 ms within
    CHUNK_SEARCH_SECONDS either side. Cutting mid-syllable costs a word at every join; a phone call
    has pauses, and landing on one is usually a matter of moving the cut by a fraction of a second.

    Short recordings come back as a single range, so nothing changes for them.
    """
    total = len(samples)
    window = int(CHUNK_SECONDS * rate)
    if total <= window:
        return [(0, total)]

    search = int(CHUNK_SEARCH_SECONDS * rate)
    probe = max(1, int(0.02 * rate))  # 20 ms

    bounds = []
    start = 0
    while start < total:
        nominal = start + window
        if nominal >= total - probe:
            bounds.append((start, total))
            break

        low = max(start + probe, nominal - search)
        high = min(total - probe, nominal + search)
        if high <= low:
            cut = nominal
        else:
            region = samples[low:high]
            usable = (len(region) // probe) * probe
            if usable < probe:
                cut = nominal
            else:
                frames = region[:usable].reshape(-1, probe)
                energy = np.abs(frames).mean(axis=1)
                cut = low + int(energy.argmin()) * probe

        bounds.append((start, cut))
        start = cut

    # A boundary landing a fraction of a second from the end leaves a sliver that costs a model call
    # and can hold nothing. Fold it into the chunk before it — one chunk slightly over the window is
    # cheaper than an extra pass, and the memory ceiling has room for it.
    if len(bounds) > 1:
        last_begin, last_end = bounds[-1]
        if (last_end - last_begin) < 2 * rate:
            previous_begin, _ = bounds[-2]
            bounds[-2] = (previous_begin, last_end)
            bounds.pop()

    return bounds


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

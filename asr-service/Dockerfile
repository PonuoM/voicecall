# syntax=docker/dockerfile:1
FROM python:3.11-slim

# libsndfile is what soundfile binds to; ffmpeg is librosa's fallback decoder for anything that is
# not a plain PCM WAV. Recordings reach this service already decoded, but the Drive corpus is mixed
# and a container that cannot read one of its formats fails at 3am rather than at build time.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg libsndfile1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY server.py .

# Bake the checkpoint into the image. Without this the model downloads on first request, so the
# first call after every restart pays several minutes and looks like a hang.
ENV HF_HOME=/opt/hf
RUN python -c "import nemo.collections.asr as a; a.models.ASRModel.from_pretrained('scb10x/typhoon-asr-realtime', map_location='cpu')"

ENV ASR_MODEL=scb10x/typhoon-asr-realtime \
    ASR_THREADS=2 \
    PYTHONUNBUFFERED=1

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=180s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health',timeout=5).status==200 else 1)"

# One worker on purpose. The model is a single in-process instance guarded by a lock, so extra
# workers would each load their own copy of the weights and then fight over the same two cores.
CMD ["uvicorn", "server:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]

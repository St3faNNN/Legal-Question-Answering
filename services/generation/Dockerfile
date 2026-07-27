FROM python:3.11-slim

WORKDIR /srv

# Install torch from the CPU wheel index first so the multi-GB CUDA build is
# never pulled; the requirements install then sees torch as already satisfied.
COPY requirements.txt .
RUN pip install --no-cache-dir torch==2.2.2 --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r requirements.txt

# Includes the committed gRPC stubs under app/pb (generated from proto/ by
# scripts/gen-proto.sh) — no codegen toolchain is baked into the image.
COPY app ./app

# The BART checkpoint is mounted at /models (never baked into the image).
ENV MODEL_PATH=/models/bart_jeopardy_final \
    DEVICE=auto \
    PORT=8002

EXPOSE 8002

# The model loads at startup (slow on CPU, and a hub stand-in may need a
# multi-GB first-run download) — keep the gRPC health probe retrying long
# enough that a slow cold start recovers instead of permanently blocking
# dependents.
HEALTHCHECK --interval=15s --timeout=5s --start-period=180s --retries=20 \
    CMD ["python", "-m", "app.healthcheck"]

CMD ["python", "-m", "app.main"]

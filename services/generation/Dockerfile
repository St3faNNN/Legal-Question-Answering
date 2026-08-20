FROM python:3.11-slim

WORKDIR /srv

# Install torch from the CPU wheel index first so the multi-GB CUDA build is
# never pulled; the requirements install then sees torch as already satisfied.
COPY requirements.txt .
# llama-cpp-python ships no glibc wheels on PyPI, and the project's own CPU wheel
# index serves musl-linked builds that fail to load here ("libc.musl-x86_64.so.1:
# cannot open shared object file") — so the native library is compiled during the
# build. The toolchain is left in place deliberately: stripping it with
# `apt-get purge --auto-remove` also takes libstdc++/libgomp, which the compiled
# libllama.so links against at runtime.
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential cmake \
    && pip install --no-cache-dir torch==2.2.2 --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r requirements.txt \
    && rm -rf /var/lib/apt/lists/*

# Includes the committed gRPC stubs under app/pb (generated from proto/ by
# scripts/gen-proto.sh) — no codegen toolchain is baked into the image.
COPY app ./app

# The default checkpoint is pulled from the hub into HF_HOME (a named volume, so
# it survives restarts). A local checkpoint can be mounted at /models instead and
# selected with MODEL_PATH.
ENV MODEL_PATH=Qwen/Qwen2.5-1.5B-Instruct \
    PROMPT_STYLE=chat \
    HF_HOME=/hf \
    DEVICE=auto \
    PORT=8002

EXPOSE 8002

# The model loads at startup, and on a cold HF cache that means downloading
# several GB first — measured at ~440s for Qwen2.5-1.5B on a slow host, which
# overran the previous 480s budget and marked the service unhealthy while it was
# still legitimately starting. Give a cold start 15 minutes; a warm cache
# reaches SERVING in well under a minute.
HEALTHCHECK --interval=15s --timeout=5s --start-period=900s --retries=20 \
    CMD ["python", "-m", "app.healthcheck"]

CMD ["python", "-m", "app.main"]

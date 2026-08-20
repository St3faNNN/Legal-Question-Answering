FROM python:3.11-slim

WORKDIR /srv

# Install the CPU torch wheel in its own layer, before copying requirements.txt,
# so adding a dependency later does not re-download the multi-GB wheel. The CUDA
# build is never pulled (CPU wheel index); requirements.txt then sees torch as
# already satisfied.
RUN pip install --no-cache-dir torch==2.2.2 --index-url https://download.pytorch.org/whl/cpu

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Includes the committed gRPC stubs under app/pb (generated from proto/ by
# scripts/gen-proto.sh) — no codegen toolchain is baked into the image.
COPY app ./app

# Corpus artifacts are mounted, never baked into the image: the citation graph
# at /data and the vector store at /vectors. HF_HOME is a named volume so the
# ~4.5 GB of BGE-M3 + reranker weights survive container restarts.
ENV GRAPH_PATH=/data/graph.graphml \
    VECTOR_DB_PATH=/vectors/vectors.sqlite \
    EMBED_MODEL_PATH=BAAI/bge-m3 \
    RERANKER_MODEL_PATH=BAAI/bge-reranker-v2-m3 \
    HF_HOME=/hf \
    DEVICE=auto \
    PORT=8001

EXPOSE 8001

# Startup loads the graph, the vector matrix, and two transformer models — and
# on a cold cache downloads ~4.6 GB of weights first, which takes many minutes
# on a slow host. Give a cold start 15 minutes; a warm cache reaches SERVING in
# well under a minute.
HEALTHCHECK --interval=15s --timeout=5s --start-period=900s --retries=20 \
    CMD ["python", "-m", "app.healthcheck"]

CMD ["python", "-m", "app.main"]

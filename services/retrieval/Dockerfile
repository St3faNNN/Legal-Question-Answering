FROM python:3.11-slim

WORKDIR /srv

# grpcio and its deps ship manylinux wheels, so no build toolchain is needed.
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Includes the committed gRPC stubs under app/pb (generated from proto/ by
# scripts/gen-proto.sh) — no codegen toolchain is baked into the image.
COPY app ./app

# The knowledge graph is mounted at /data (never baked into the image).
ENV GRAPH_PATH=/data/knowledge_graph_with_coref.graphml \
    PORT=8001

EXPOSE 8001

# Startup loads a 45 MB graph and builds the entity index — allow a long start
# period, then probe the gRPC health service.
HEALTHCHECK --interval=15s --timeout=5s --start-period=90s --retries=5 \
    CMD ["python", "-m", "app.healthcheck"]

CMD ["python", "-m", "app.main"]

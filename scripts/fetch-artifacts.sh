#!/usr/bin/env bash
#
# Verifies the corpus artifacts the Retrieval Service needs are in place:
#
#   artifacts/vector/vectors.sqlite   BGE-M3 chunk embeddings + chunk text
#   artifacts/graph/graph.graphml     article cross-reference graph
#
# Both are built from the same corpus by the offline indexing pipeline and are
# not downloadable from here — artifacts/ is gitignored and the pair is specific
# to the deployment. Copy them in, then run this to confirm they are readable
# and mutually consistent before `docker compose up`.
#
# The model weights are no longer fetched: the Generation Service pulls
# Qwen2.5-1.5B-Instruct and the Retrieval Service pulls BGE-M3 plus
# bge-reranker-v2-m3 from the Hugging Face hub into the shared hf-cache volume
# on first boot.
#
# Requires: python3 (stdlib only). Override the interpreter with PYTHON=...

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VECTOR_FILE="$ROOT_DIR/artifacts/vector/vectors.sqlite"
GRAPH_FILE="$ROOT_DIR/artifacts/graph/graph.graphml"

missing=0
for path in "$VECTOR_FILE" "$GRAPH_FILE"; do
    if [ ! -f "$path" ]; then
        echo "ERROR: missing artifact: $path" >&2
        missing=1
    fi
done
if [ "$missing" -ne 0 ]; then
    echo "Copy both corpus artifacts into artifacts/ and re-run." >&2
    exit 1
fi

"${PYTHON:-python3}" - "$VECTOR_FILE" "$GRAPH_FILE" <<'PY'
import sqlite3
import sys
import xml.etree.ElementTree as ET

vector_path, graph_path = sys.argv[1], sys.argv[2]

connection = sqlite3.connect(f"file:{vector_path}?mode=ro", uri=True)
meta = dict(connection.execute("SELECT key, value FROM meta"))
chunks = connection.execute("SELECT COUNT(*) FROM chunks").fetchone()[0]
vectors = connection.execute("SELECT COUNT(*) FROM vectors").fetchone()[0]
orphans = connection.execute(
    "SELECT COUNT(*) FROM chunks c LEFT JOIN vectors v ON v.chunk_id = c.id WHERE v.chunk_id IS NULL"
).fetchone()[0]
blob = connection.execute("SELECT dense FROM vectors LIMIT 1").fetchone()[0]
connection.close()

dim = int(meta.get("dim", 0))
print(f"[vector] {chunks} chunks, {vectors} vectors, model={meta.get('embed_model', '?')}, dim={dim}")

if orphans:
    sys.exit(f"[vector] ERROR: {orphans} chunks have no embedding")
if len(blob) != dim * 4:
    sys.exit(f"[vector] ERROR: dense blob is {len(blob)} bytes, expected {dim * 4} ({dim} float32)")

ns = "{http://graphml.graphdrawing.org/xmlns}"
root = ET.parse(graph_path).getroot()
nodes = root.iter(f"{ns}node")
node_ids = {node.get("id") for node in nodes}
edges = list(root.iter(f"{ns}edge"))
print(f"[graph]  {len(node_ids)} articles, {len(edges)} cross-references")

edge_keys = {key.get("id") for key in root.iter(f"{ns}key") if key.get("for") == "edge"}
if not any(key.get("attr.name") == "confidence" for key in root.iter(f"{ns}key")):
    sys.exit("[graph]  ERROR: no edge 'confidence' attribute — expansion ranking would be arbitrary")

# The two artifacts are joined on unit_key; without an overlap the graph arm
# silently contributes nothing.
connection = sqlite3.connect(f"file:{vector_path}?mode=ro", uri=True)
units = {row[0] for row in connection.execute("SELECT DISTINCT unit_key FROM chunks")}
connection.close()
shared = units & node_ids
print(f"[join]   {len(shared)} article ids present in both artifacts")
if not shared:
    sys.exit("[join]   ERROR: no chunk unit_key matches any graph node id — the two artifacts are not from the same corpus")
PY

echo "All artifacts ready under $ROOT_DIR/artifacts/"

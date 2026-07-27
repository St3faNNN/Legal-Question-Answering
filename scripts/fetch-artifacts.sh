#!/usr/bin/env bash
#
# Fetches the large AI artifacts the Python services need. Idempotent: existing
# targets are skipped, so re-running after a partial failure is safe.
#
#   artifacts/graph/knowledge_graph_with_coref.graphml   (~45 MB, plain git blob)
#   artifacts/models/bart_jeopardy_final/                (~1.6 GB, weights via Git LFS)
#
# Requires: curl, git, git-lfs. Never commit artifacts/ — it is gitignored.

set -euo pipefail

REPO_URL="https://github.com/St3faNNN/GraphRAG-JeopardyEvaluation"
RAW_BASE="https://raw.githubusercontent.com/St3faNNN/GraphRAG-JeopardyEvaluation/main"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GRAPH_DIR="$ROOT_DIR/artifacts/graph"
MODEL_DIR="$ROOT_DIR/artifacts/models/bart_jeopardy_final"
GRAPH_FILE="$GRAPH_DIR/knowledge_graph_with_coref.graphml"

file_size() {
    if stat --version >/dev/null 2>&1; then
        stat -c%s "$1"
    else
        stat -f%z "$1"
    fi
}

# --- Knowledge graph (plain blob, direct download) ---------------------------
if [ -f "$GRAPH_FILE" ] && [ "$(file_size "$GRAPH_FILE")" -gt 40000000 ]; then
    echo "[graph] already present, skipping ($GRAPH_FILE)"
else
    echo "[graph] downloading knowledge_graph_with_coref.graphml (~45 MB)..."
    mkdir -p "$GRAPH_DIR"
    curl -fL --retry 3 "$RAW_BASE/knowledge%20graph/knowledge_graph_with_coref.graphml" \
        -o "$GRAPH_FILE.part"
    mv "$GRAPH_FILE.part" "$GRAPH_FILE"
    echo "[graph] done: $(file_size "$GRAPH_FILE") bytes"
fi

# --- BART checkpoint (pytorch_model.bin is stored in Git LFS) -----------------
if [ -f "$MODEL_DIR/pytorch_model.bin" ] && [ "$(file_size "$MODEL_DIR/pytorch_model.bin")" -gt 100000000 ]; then
    echo "[model] already present, skipping ($MODEL_DIR)"
else
    if ! git lfs version >/dev/null 2>&1; then
        echo "[model] ERROR: git-lfs is required to fetch the model weights." >&2
        exit 1
    fi
    TMP_DIR="$(mktemp -d)"
    trap 'rm -rf "$TMP_DIR"' EXIT
    echo "[model] cloning repo (weights ~1.6 GB via LFS, this takes a while)..."
    GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 "$REPO_URL" "$TMP_DIR/repo"
    git -C "$TMP_DIR/repo" lfs pull --include "knowledge graph/bart_jeopardy_final/*"
    mkdir -p "$MODEL_DIR"
    cp "$TMP_DIR/repo/knowledge graph/bart_jeopardy_final/"* "$MODEL_DIR/"

    # A ~130-byte pytorch_model.bin is an LFS pointer, not real weights.
    BIN_SIZE="$(file_size "$MODEL_DIR/pytorch_model.bin")"
    if [ "$BIN_SIZE" -lt 100000000 ]; then
        echo "[model] ERROR: pytorch_model.bin is $BIN_SIZE bytes — an LFS pointer, not weights." >&2
        echo "[model] Check that git-lfs is installed and 'git lfs pull' succeeded." >&2
        exit 1
    fi
    echo "[model] done: pytorch_model.bin is $BIN_SIZE bytes"
fi

echo "All artifacts ready under $ROOT_DIR/artifacts/"

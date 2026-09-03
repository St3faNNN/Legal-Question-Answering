#!/usr/bin/env bash
#
# Generates gRPC stubs from the canonical contracts in proto/ into:
#   - services/retrieval/app/pb/   (Python)
#   - app/Ai/Pb/                   (PHP — requires protoc + grpc_php_plugin)
#
# The Python stubs are committed so the service Docker images need no codegen
# toolchain at build time. The PHP stubs are generated inside the Laravel Docker
# build (where protoc + grpc_php_plugin are installed); run the PHP section here
# only if you have those tools locally.
#
# Usage:
#   bash scripts/gen-proto.sh            # Python stubs only (needs python + grpcio-tools)
#   bash scripts/gen-proto.sh --php      # PHP stubs only (needs protoc + grpc_php_plugin)
#
# The two modes are separate on purpose: the Laravel Docker image has protoc but
# no python, and the Python service images have neither (their stubs are
# committed). So --php must never invoke the Python toolchain.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTO_DIR="$ROOT_DIR/proto"
PYTHON="${PYTHON:-python}"

gen_python() {
    local service="$1"
    local proto="$2"
    local out="$ROOT_DIR/services/$service/app/pb"

    mkdir -p "$out"
    touch "$out/__init__.py"
    "$PYTHON" -m grpc_tools.protoc \
        -I "$PROTO_DIR" \
        --python_out="$out" \
        --grpc_python_out="$out" \
        "$PROTO_DIR/$proto"

    # grpc_tools emits `import <name>_pb2` (top-level); rewrite to a package-
    # relative import so the stubs work when imported as app.pb.<name>_pb2_grpc.
    local base="${proto%.proto}"
    sed -i -E "s/^import ${base}_pb2 /from . import ${base}_pb2 /" \
        "$out/${base}_pb2_grpc.py"
    echo "[python] $service: generated ${base}_pb2.py, ${base}_pb2_grpc.py"
}

if [ "${1:-}" == "--php" ]; then
    # PHP-only. protoc writes the php_namespace verbatim as a directory tree
    # ("App/Ai/Pb/..."). Generate into a temp dir, then move that tree under
    # the lowercase app/ so it is covered by the existing PSR-4 map (App\ =>
    # app/) with no composer.json change.
    plugin="$(command -v grpc_php_plugin)"
    if [ -z "$plugin" ]; then
        echo "error: grpc_php_plugin not found on PATH (install protobuf-compiler-grpc)" >&2
        exit 1
    fi
    tmp="$(mktemp -d)"
    protoc \
        -I "$PROTO_DIR" \
        --php_out="$tmp" \
        --grpc_out="$tmp" \
        --plugin=protoc-gen-grpc="$plugin" \
        "$PROTO_DIR/retrieval.proto"
    mkdir -p "$ROOT_DIR/app/Ai/Pb"
    cp -r "$tmp/App/Ai/Pb/." "$ROOT_DIR/app/Ai/Pb/"
    rm -rf "$tmp"
    echo "[php] generated stubs into app/Ai/Pb"
else
    # Python-only (default): regenerate the committed service stubs.
    gen_python retrieval retrieval.proto
fi

echo "Done."

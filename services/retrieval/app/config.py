"""Environment-driven configuration for the Retrieval Service."""

import logging
import os
from dataclasses import dataclass

logger = logging.getLogger("retrieval")


@dataclass(frozen=True)
class Settings:
    graph_path: str
    vector_db_path: str
    embed_model_path: str
    reranker_model_path: str
    rerank_backend: str  # "none" | "torch" | "onnx"
    rerank_onnx_repo: str
    rerank_onnx_file: str
    device: str  # "auto" | "cpu" | "cuda"
    dtype: str  # "auto" | "float32" | "bfloat16" | "float16"
    quantize: str  # "none" | "int8"
    use_sparse: bool
    dense_top_k: int
    sparse_top_k: int
    graph_hops: int
    graph_max_expand: int
    rrf_k: int
    candidate_pool: int
    retrieval_top_n: int
    rerank_max_length: int
    context_char_budget: int


def _flag(name: str, default: str) -> bool:
    return os.environ.get(name, default).strip().lower() in {"1", "true", "yes", "on"}


def _resolve_top_n(default: int = 10) -> int:
    """How many passages to return. Reads RETRIEVAL_TOP_N; falls back to the
    deprecated RERANK_TOP_N (with a warning) so existing .env files keep working
    now that nothing reranks. Empty strings — which docker-compose passes for an
    unset ``${VAR:-}`` — count as unset."""
    new = os.environ.get("RETRIEVAL_TOP_N") or None
    old = os.environ.get("RERANK_TOP_N") or None
    if new is not None:
        return int(new)
    if old is not None:
        logger.warning(
            "RERANK_TOP_N is deprecated (the reranker was removed) — use RETRIEVAL_TOP_N. "
            "Honouring RERANK_TOP_N=%s for now.",
            old,
        )
        return int(old)
    return default


def get_settings() -> Settings:
    return Settings(
        graph_path=os.environ.get("GRAPH_PATH", "/data/graph.graphml"),
        vector_db_path=os.environ.get("VECTOR_DB_PATH", "/vectors/vectors.sqlite"),
        embed_model_path=os.environ.get("EMBED_MODEL_PATH", "BAAI/bge-m3"),
        reranker_model_path=os.environ.get("RERANKER_MODEL_PATH", "BAAI/bge-reranker-v2-m3"),
        # Reranker execution backend.
        #
        # DECISION (deliberate, measured): the cross-encoder reranker is OUT of
        # the default path. "none" (default) never fetches, loads, or holds the
        # reranker; ordering falls through to the RRF-fused pool. The measured
        # trade, none/pool=24 vs the reranked onnx/pool=16 it replaced:
        #     recall@16   0.8333  vs  0.8125   (a wider window recovers what the
        #                                       cross-encoder's reordering bought)
        #     latency     ~0.3s   vs  3.9s     (no per-candidate forward passes)
        #     retrieval   2.98GiB vs  4.00GiB  (the ~1 GiB the reranker held)
        # Why it's worth nothing to lose: the product shows the cited provisions
        # to legal practitioners who read the articles themselves, so compressing
        # the correct results into a shorter, better-ordered list bought them
        # nothing — they scan the list. See tests/eval/results and
        # [[reranker-removed-from-retrieval]].
        # One thing lost with it: the rerank score was a clean abstention signal
        # (unanswerable questions scored below a threshold); RRF fused scores do
        # NOT separate answerable from unanswerable, so that guard is gone.
        #
        #   "torch" keeps the transformers cross-encoder path unchanged.
        #   "onnx" runs a prebuilt int8 ONNX export via onnxruntime — markedly
        #     faster on an AVX2 CPU, where torch's dynamic int8 has no VNNI to
        #     accelerate it.
        # Both are retained for the eval harness to switch back and compare. The
        # embedder is unaffected. For torch/onnx the tokenizer is always
        # RERANKER_MODEL_PATH (same tokens), so a ranking difference between those
        # two is model numerics, not tokenization.
        rerank_backend=os.environ.get("RERANK_BACKEND", "none"),
        rerank_onnx_repo=os.environ.get("RERANK_ONNX_REPO", "onnx-community/bge-reranker-v2-m3-ONNX"),
        rerank_onnx_file=os.environ.get("RERANK_ONNX_FILE", "onnx/model_int8.onnx"),
        device=os.environ.get("DEVICE", "auto"),
        # float32, deliberately. bfloat16 would halve the encoder pair from
        # ~4.6 GB to ~2.3 GB, but PyTorch has no fast CPU path for bf16 without
        # AMX and falls back to emulation — measured at over 10 minutes for a
        # single query, against tens of seconds at float32. Only set bfloat16 on
        # a GPU, or on a CPU new enough to have AMX.
        dtype=os.environ.get("MODEL_DTYPE", "float32"),
        # Dynamic int8 quantization of the embedder's Linear layers. Off by
        # default so local dev and the test doubles are unaffected. The RAM saving
        # is marginal, NOT the ~4.6 GB -> ~2 GB once claimed here: retrieval
        # measured 5.14 GiB *with* int8. What int8 actually bought was latency —
        # it roughly halved the (former) cross-encoder's forward pass on this
        # AVX2-only CPU. With the reranker gone int8 only touches the embedder, so
        # its benefit is now small; kept for now, but it is a candidate to drop.
        quantize=os.environ.get("QUANTIZE", "none"),
        # BGE-M3's lexical arm needs the sparse_linear head, which ships beside
        # the model on the hub. When it cannot be fetched the service degrades
        # to dense-only rather than failing to start.
        use_sparse=_flag("USE_SPARSE", "true"),
        dense_top_k=int(os.environ.get("DENSE_TOP_K", "50")),
        sparse_top_k=int(os.environ.get("SPARSE_TOP_K", "50")),
        graph_hops=int(os.environ.get("GRAPH_HOPS", "1")),
        graph_max_expand=int(os.environ.get("GRAPH_MAX_EXPAND", "20")),
        rrf_k=int(os.environ.get("RRF_K", "60")),
        # Size of the RRF-fused candidate pool. With the reranker gone there is no
        # per-candidate scoring cost, so this is nearly free to widen — and it
        # must be >= RETRIEVAL_TOP_N, or the pool caps the result (build_context
        # warns if it isn't). 24 gives the returned window headroom and lets graph
        # expansions compete; a wider pool recovers the recall the reranker's
        # reordering used to buy (recall@16 0.8125 -> 0.8333 going 16 -> 24).
        candidate_pool=int(os.environ.get("CANDIDATE_POOL", "24")),
        # How many passages to return. RETRIEVAL_TOP_N, formerly RERANK_TOP_N
        # (nothing reranks now — the pool is returned in RRF order); the old name
        # is still honoured with a warning. See _resolve_top_n.
        retrieval_top_n=_resolve_top_n(10),
        # Chunks average ~856 characters (~300 tokens); 256 covers the topic of
        # a legal article, and cost is roughly linear in sequence length.
        rerank_max_length=int(os.environ.get("RERANK_MAX_LENGTH", "256")),
        # Sized against the generator's 8192-token input window: Macedonian
        # Cyrillic runs at roughly 2-3 characters per token, so ~12k characters
        # leaves ample room for the system prompt and the answer. The generator's
        # own tokenizer still enforces the real limit; this bounds what crosses
        # the wire and is what context_items counts.
        context_char_budget=int(os.environ.get("CONTEXT_CHAR_BUDGET", "12000")),
    )

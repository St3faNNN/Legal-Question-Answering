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
    context_char_budget: int


def _flag(name: str, default: str) -> bool:
    return os.environ.get(name, default).strip().lower() in {"1", "true", "yes", "on"}


def _resolve_top_n(default: int = 10) -> int:
    """How many passages to return, from RETRIEVAL_TOP_N.

    An empty string — which docker-compose passes for an unset ``${VAR:-}`` —
    counts as unset. The RERANK_TOP_N alias this used to honour was removed with
    the reranker; nothing reads it any more, and a stale setting is now silently
    ignored rather than silently obeyed.
    """
    value = os.environ.get("RETRIEVAL_TOP_N") or None

    return int(value) if value is not None else default


def get_settings() -> Settings:
    return Settings(
        graph_path=os.environ.get("GRAPH_PATH", "/data/graph.graphml"),
        vector_db_path=os.environ.get("VECTOR_DB_PATH", "/vectors/vectors.sqlite"),
        embed_model_path=os.environ.get("EMBED_MODEL_PATH", "BAAI/bge-m3"),
        device=os.environ.get("DEVICE", "auto"),
        # float32, deliberately. bfloat16 would halve the encoder pair from
        # ~4.6 GB to ~2.3 GB, but PyTorch has no fast CPU path for bf16 without
        # AMX and falls back to emulation — measured at over 10 minutes for a
        # single query, against tens of seconds at float32. Only set bfloat16 on
        # a GPU, or on a CPU new enough to have AMX.
        dtype=os.environ.get("MODEL_DTYPE", "float32"),
        # Dynamic int8 quantization of the embedder's Linear layers. Off by
        # default here so local dev and the test doubles are unaffected;
        # docker-compose sets int8 for the deployed service.
        #
        # What it does, now that the embedder is the only model left: it quantizes
        # BGE-M3's Linear weights to int8 and dequantizes per matmul. The RAM
        # saving is marginal and was never the ~4.6 GB -> ~2 GB once claimed here —
        # retrieval measured 5.14 GiB *with* int8 in place.
        #
        # The old justification is void. int8's measured benefit was halving the
        # CROSS-ENCODER's forward pass, and the cross-encoder is gone. This CPU is
        # AVX2-only with no VNNI, so there is no native int8 GEMM to hit: the
        # dequantize-per-matmul overhead can offset the narrower weights entirely.
        #
        # Kept because warm retrieval is already ~0.1-0.3s and dominated by
        # tokenization and the sparse arm, so neither keeping nor dropping it moves
        # a number anyone notices — and flipping it would invalidate the eval runs
        # that were captured with QUANTIZE=int8. It is a free candidate to drop the
        # next time the eval set is re-run; there is no longer a reason to keep it
        # beyond continuity.
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
        # Size of the RRF-fused candidate pool, and now the ONLY ranking stage.
        # It must be >= RETRIEVAL_TOP_N or the pool caps the result (build_context
        # warns if it isn't).
        #
        # 24 still earns its keep with nothing downstream: the pool is not a
        # shortlist for a reranker any more, it is the population RRF orders. A
        # wider pool lets dense, sparse and graph-expansion candidates compete
        # before the window is taken, and that competition is what recovered the
        # recall the reranker's reordering used to buy (recall@16 0.8125 -> 0.8333
        # going 16 -> 24). Widening costs one more RRF term per candidate — no
        # model call — so the only price is the fusion loop.
        #
        # It is not free above the returned window, though: candidates ranked
        # 11-24 are computed and then discarded by the RETRIEVAL_TOP_N=10 slice.
        # That headroom is what makes the ordering robust, but if the window ever
        # rises to 16 the pool should rise with it.
        candidate_pool=int(os.environ.get("CANDIDATE_POOL", "24")),
        # How many passages to return, taken from the head of the RRF order.
        # Also sizes the app's provisions panel (config/ai.php reads the same
        # variable), so the panel can never be shorter than the generation
        # context. See _resolve_top_n.
        retrieval_top_n=_resolve_top_n(10),
        # Sized against the generator's 8192-token input window: Macedonian
        # Cyrillic runs at roughly 2-3 characters per token, so ~12k characters
        # leaves ample room for the system prompt and the answer. The generator's
        # own tokenizer still enforces the real limit; this bounds what crosses
        # the wire and is what context_items counts.
        context_char_budget=int(os.environ.get("CONTEXT_CHAR_BUDGET", "12000")),
    )

"""GraphRAG Retrieval Service (gRPC): question in, fused corpus context out.

Retrieval is hybrid over two artifacts built from the same corpus. The vector
store (vectors.sqlite) supplies BGE-M3 dense and lexical-sparse rankings; the
citation graph (graph.graphml) supplies the articles those hits cross-reference.
The three rankings are fused with RRF; when a cross-encoder reranker is
configured (RERANK_BACKEND=torch|onnx) the fused pool is reranked, otherwise
(RERANK_BACKEND=none, the default) the RRF order stands. Either way the top
slice is packed into the generator's context.

Everything expensive — both transformer models, the vector matrix, the graph —
is loaded once at server startup, never per request.
"""

import logging
import os
import time
from concurrent import futures
from typing import TYPE_CHECKING

import grpc
from grpc_health.v1 import health, health_pb2, health_pb2_grpc

from .citations import (
    citation_of,
    expand,
    load_citation_graph,
    outgoing_references,
    reference_triples,
    resolve_unit,
)
from .config import Settings, get_settings
from .fusion import Candidate, pack_context, reciprocal_rank_fusion
from .pb import retrieval_pb2, retrieval_pb2_grpc
from .vectors import ChunkHit, VectorStore

if TYPE_CHECKING:  # pragma: no cover
    # Imported for typing only: the embedder and reranker pull in torch, and the
    # servicer, fusion, and wire contract are all testable against doubles
    # without a deep-learning stack installed. build_context() imports them for
    # real when the service actually starts.
    from .embedder import QueryEmbedder
    from .reranker import CrossEncoderReranker

logger = logging.getLogger("retrieval")

SERVICE_NAME = "graphrag.retrieval.v1.Retrieval"

#: How many of the top vector hits seed graph expansion. Expanding from every
#: hit would let the tail of the vector ranking drag in unrelated articles.
GRAPH_SEED_COUNT = 5


class RetrievalContext:
    """The loaded corpus artifacts and models.

    Components are injected so tests can exercise the full fusion path against
    lightweight doubles without downloading a model.
    """

    def __init__(
        self,
        settings: Settings,
        store: VectorStore,
        graph,
        embedder: "QueryEmbedder",
        reranker: "CrossEncoderReranker | None",
    ):
        self.settings = settings
        self.store = store
        self.graph = graph
        self.embedder = embedder
        self.reranker = reranker

    def retrieve(self, question: str) -> dict:
        start = time.perf_counter()
        stage: dict[str, int] = {}

        _t = time.perf_counter()
        dense_vector, sparse_weights = self.embedder.encode(question)
        stage["embed"] = round((time.perf_counter() - _t) * 1000)

        _t = time.perf_counter()
        rankings: dict[str, list[Candidate]] = {
            "dense": _candidates(self.store.search_dense(dense_vector, self.settings.dense_top_k)),
        }
        if sparse_weights:
            rankings["sparse"] = _candidates(
                self.store.search_sparse(sparse_weights, self.settings.sparse_top_k)
            )
        stage["search"] = round((time.perf_counter() - _t) * 1000)

        _t = time.perf_counter()
        vector_ranking = reciprocal_rank_fusion(
            rankings, k=self.settings.rrf_k, limit=self.settings.candidate_pool
        )
        fusion_ms = (time.perf_counter() - _t) * 1000

        _t = time.perf_counter()
        graph_ranking = self._expand(vector_ranking)
        stage["graph"] = round((time.perf_counter() - _t) * 1000)

        _t = time.perf_counter()
        pool = reciprocal_rank_fusion(
            {"vector": vector_ranking, "graph": graph_ranking},
            k=self.settings.rrf_k,
            limit=self.settings.candidate_pool,
        )
        stage["fusion"] = round(fusion_ms + (time.perf_counter() - _t) * 1000)

        _t = time.perf_counter()
        top = self._rerank(question, pool)
        stage["rerank"] = round((time.perf_counter() - _t) * 1000)

        _t = time.perf_counter()
        context, context_items = pack_context(top, self.settings.context_char_budget)
        stage["pack"] = round((time.perf_counter() - _t) * 1000)

        unit_keys = [candidate.unit_key for candidate in top if candidate.unit_key]

        latency_ms = round((time.perf_counter() - start) * 1000)

        # Per-stage breakdown for latency diagnosis, in the module's %-style. This
        # is instrumentation only — no retrieval logic is changed.
        logger.info(
            "retrieve stage_ms embed=%d search=%d fusion=%d graph=%d rerank=%d pack=%d total=%d",
            stage["embed"],
            stage["search"],
            stage["fusion"],
            stage["graph"],
            stage["rerank"],
            stage["pack"],
            latency_ms,
        )

        return {
            "context": context,
            "context_items": context_items,
            # The citations of the reranked articles. Kept under the wire's
            # existing "entities" field: same role, corpus-appropriate content.
            "entities": _unique([candidate.citation for candidate in top if candidate.citation]),
            "triples": reference_triples(self.graph, unit_keys, self.settings.retrieval_top_n),
            "chunks": [
                {
                    "id": str(candidate.chunk_id),
                    "text": candidate.text,
                    "source": candidate.citation or citation_of(self.graph, candidate.unit_key),
                    "score": float(candidate.rerank_score or candidate.fused_score),
                }
                for candidate in top
            ],
            "latency_ms": latency_ms,
        }

    def references(self, law: str, article: str) -> dict:
        """Outgoing citations of one article, resolved from (law, article).

        Pure graph traversal — no embedding, no vector search — so it is cheap
        and deterministic. Returns found=False (not an error) when the article
        is not in the corpus, letting the caller say so plainly.
        """
        unit_key = resolve_unit(self.graph, law, article)
        if unit_key is None:
            return {"found": False, "source_citation": "", "references": []}

        return {
            "found": True,
            "source_citation": citation_of(self.graph, unit_key),
            "references": outgoing_references(self.graph, unit_key),
        }

    def _expand(self, vector_ranking: list[Candidate]) -> list[Candidate]:
        """Turn the citation neighbours of the strongest vector hits back into
        retrievable chunks, so the graph competes in the same pool as the text."""
        seeds = _unique([c.unit_key for c in vector_ranking[:GRAPH_SEED_COUNT] if c.unit_key])
        if not seeds:
            return []

        expansions = expand(
            self.graph, seeds, self.settings.graph_hops, self.settings.graph_max_expand
        )
        hits = self.store.chunks_for_units([item.unit_key for item in expansions])
        return _candidates(hits)

    def _rerank(self, question: str, pool: list[Candidate]) -> list[Candidate]:
        if not pool:
            return []

        # No cross-encoder configured (RERANK_BACKEND=none): the pool is already
        # in RRF-fused order, so take the top slice as-is. rerank_score stays
        # None, and the fused score is what surfaces on the wire.
        if self.reranker is None:
            return pool[: self.settings.retrieval_top_n]

        scores = self.reranker.score(question, [candidate.text for candidate in pool])
        for candidate, score in zip(pool, scores):
            candidate.rerank_score = float(score)

        ranked = sorted(pool, key=lambda c: -(c.rerank_score if c.rerank_score is not None else 0.0))
        return ranked[: self.settings.retrieval_top_n]


def _candidates(hits: list[ChunkHit]) -> list[Candidate]:
    return [
        Candidate(
            key=str(hit.chunk_id),
            text=hit.text,
            unit_key=hit.unit_key,
            citation=hit.citation,
            chunk_id=hit.chunk_id,
        )
        for hit in hits
    ]


def _unique(values: list[str]) -> list[str]:
    seen: set[str] = set()
    ordered: list[str] = []
    for value in values:
        if value not in seen:
            seen.add(value)
            ordered.append(value)
    return ordered


def _check_config(settings: Settings) -> None:
    """Startup sanity checks that would otherwise degrade results silently.

    RETRIEVAL_TOP_N > CANDIDATE_POOL is impossible to honour: the returned window
    is sliced from the fused pool, so a window wider than the pool just returns
    the pool. This is a WARNING, not a hard failure, on purpose — the service
    still answers (with ``candidate_pool`` passages), so refusing to start would
    trade a minor, self-correcting degradation for an outage on a typo. The
    warning surfaces the misconfiguration in the logs instead.
    """
    if settings.retrieval_top_n > settings.candidate_pool:
        logger.warning(
            "RETRIEVAL_TOP_N=%d exceeds CANDIDATE_POOL=%d; the pool caps the result, so at "
            "most %d passages can be returned. Raise CANDIDATE_POOL to at least RETRIEVAL_TOP_N.",
            settings.retrieval_top_n,
            settings.candidate_pool,
            settings.candidate_pool,
        )


def _build_reranker(settings: Settings):
    """Constructs the reranker for the configured backend, or returns None when
    reranking is disabled.

    For ``RERANK_BACKEND=none`` (the default) nothing is imported from
    :mod:`.reranker`, so no cross-encoder is fetched, loaded, or held in memory;
    ordering falls through to the RRF-fused pool. ``torch`` and ``onnx`` build
    their respective backends (the caller loads them). An unknown value fails
    loudly rather than silently degrading.
    """
    backend = settings.rerank_backend
    if backend == "none":
        logger.info("Reranker disabled (RERANK_BACKEND=none); ordering by RRF fusion")
        return None
    if backend == "torch":
        from .reranker import CrossEncoderReranker

        return CrossEncoderReranker(
            settings.reranker_model_path,
            settings.device,
            max_length=settings.rerank_max_length,
            dtype=settings.dtype,
            quantize=settings.quantize,
        )
    if backend == "onnx":
        from .reranker import OnnxCrossEncoderReranker

        return OnnxCrossEncoderReranker(
            settings.reranker_model_path,
            settings.rerank_onnx_repo,
            settings.rerank_onnx_file,
            max_length=settings.rerank_max_length,
        )
    raise ValueError(
        f"Unknown RERANK_BACKEND: {backend!r} (expected 'none', 'torch', or 'onnx')"
    )


def build_context(settings: Settings) -> RetrievalContext:
    """Loads every artifact and model. Slow by design and done once at startup."""
    from .embedder import QueryEmbedder

    _check_config(settings)

    logger.info("Loading citation graph from %s", settings.graph_path)
    graph = load_citation_graph(settings.graph_path)
    logger.info(
        "Citation graph loaded: %d articles, %d cross-references",
        graph.number_of_nodes(),
        graph.number_of_edges(),
    )

    store = VectorStore(settings.vector_db_path)
    store.load()

    embedder = QueryEmbedder(
        settings.embed_model_path,
        settings.device,
        settings.use_sparse,
        dtype=settings.dtype,
        quantize=settings.quantize,
    )
    embedder.load()

    # Opt-in prompt-injection test overlay. Off unless INJECTION_FIXTURE_PATH is
    # set (production and the eval harness never set it). Appends test chunks to
    # the in-memory store only; vectors.sqlite is untouched.
    fixture_path = os.environ.get("INJECTION_FIXTURE_PATH", "").strip()
    if fixture_path:
        from .injection_fixture import load_injection_fixture

        load_injection_fixture(store, embedder, fixture_path)

    if store.embed_model and store.embed_model != settings.embed_model_path:
        # Query and document vectors must come from the same model or cosine
        # similarity between them is meaningless.
        logger.warning(
            "Vector store was built with %s but the service is querying with %s",
            store.embed_model,
            settings.embed_model_path,
        )

    # Reranker backend selection. none (default) loads nothing and lets the RRF
    # order stand; torch/onnx build and load a cross-encoder. See _build_reranker.
    reranker = _build_reranker(settings)
    if reranker is not None:
        reranker.load()

    return RetrievalContext(settings, store, graph, embedder, reranker)


class RetrievalServicer(retrieval_pb2_grpc.RetrievalServicer):
    def __init__(self, context: RetrievalContext):
        self._context = context

    def Retrieve(self, request, grpc_context):
        if not request.question or not request.question.strip():
            grpc_context.abort(grpc.StatusCode.INVALID_ARGUMENT, "question must not be empty")

        result = self._context.retrieve(request.question)
        return retrieval_pb2.RetrieveResponse(
            context=result["context"],
            entities=result["entities"],
            triples=[retrieval_pb2.Triple(**triple) for triple in result["triples"]],
            chunks=[retrieval_pb2.Chunk(**chunk) for chunk in result["chunks"]],
            context_items=result["context_items"],
            latency_ms=result["latency_ms"],
        )

    def References(self, request, grpc_context):
        if not request.law or not request.law.strip() or not request.article or not request.article.strip():
            grpc_context.abort(grpc.StatusCode.INVALID_ARGUMENT, "law and article must not be empty")

        result = self._context.references(request.law, request.article)
        return retrieval_pb2.ReferencesResponse(
            found=result["found"],
            source_citation=result["source_citation"],
            references=[retrieval_pb2.Reference(**reference) for reference in result["references"]],
        )


def build_server(context: RetrievalContext, max_workers: int = 8) -> grpc.Server:
    """Assembles a gRPC server with the Retrieval and standard Health services.
    The caller binds a port and starts it (see serve() and the tests)."""
    server = grpc.server(futures.ThreadPoolExecutor(max_workers=max_workers))
    retrieval_pb2_grpc.add_RetrievalServicer_to_server(RetrievalServicer(context), server)

    health_servicer = health.HealthServicer()
    health_pb2_grpc.add_HealthServicer_to_server(health_servicer, server)
    health_servicer.set("", health_pb2.HealthCheckResponse.SERVING)
    health_servicer.set(SERVICE_NAME, health_pb2.HealthCheckResponse.SERVING)

    return server


def serve() -> None:
    logging.basicConfig(level=logging.INFO)
    context = build_context(get_settings())
    server = build_server(context, max_workers=int(os.environ.get("GRPC_MAX_WORKERS", "8")))

    port = os.environ.get("PORT", "8001")
    server.add_insecure_port(f"[::]:{port}")
    server.start()
    logger.info("Retrieval gRPC server listening on :%s", port)
    server.wait_for_termination()


if __name__ == "__main__":
    serve()

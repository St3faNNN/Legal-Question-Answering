"""GraphRAG Retrieval Service (gRPC): question in, knowledge-graph context out.

The graph and spaCy entity index are loaded once at server startup (never per
request). The retrieval algorithm — entity linking, 2-hop/top-k subgraph
extraction, confidence-ranked triple linearization — is unchanged from the
reference notebook and lives in graph.py / linker.py.
"""

import logging
import os
import time
from concurrent import futures

import grpc
import spacy
from grpc_health.v1 import health, health_pb2, health_pb2_grpc

from .config import Settings, get_settings
from .graph import (
    extract_subgraph,
    linearize_triples,
    load_graph,
    merge_subgraphs,
    ranked_triples,
)
from .linker import EntityIndex, QuestionLinker
from .pb import retrieval_pb2, retrieval_pb2_grpc

logger = logging.getLogger("retrieval")

SERVICE_NAME = "graphrag.retrieval.v1.Retrieval"


class RetrievalContext:
    """The loaded knowledge graph plus the entity linker, built once at startup."""

    def __init__(self, settings: Settings):
        self.settings = settings
        logger.info("Loading knowledge graph from %s", settings.graph_path)
        self.graph = load_graph(settings.graph_path)
        logger.info(
            "Graph loaded: %d nodes, %d edges",
            self.graph.number_of_nodes(),
            self.graph.number_of_edges(),
        )
        self.index = EntityIndex(self.graph)
        # Only tok2vec + ner are needed for entity extraction.
        nlp = spacy.load(
            "en_core_web_sm", exclude=["tagger", "parser", "attribute_ruler", "lemmatizer"]
        )
        self.linker = QuestionLinker(nlp, self.index, settings.max_ngram, settings.max_entities)

    def retrieve(self, question: str) -> dict:
        start = time.perf_counter()
        linked = self.linker.link(question)
        subgraphs = [
            extract_subgraph(entity.node_id, self.graph, self.settings.max_hops, self.settings.top_k)
            for entity in linked
        ]
        merged = merge_subgraphs(subgraphs)
        triples = ranked_triples(
            merged, [entity.node_id for entity in linked], self.settings.max_triples
        )
        return {
            "context": linearize_triples(triples),
            "entities": [entity.node_id for entity in linked],
            "triples": triples,
            "latency_ms": round((time.perf_counter() - start) * 1000),
        }


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
            latency_ms=result["latency_ms"],
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
    context = RetrievalContext(get_settings())
    server = build_server(context, max_workers=int(os.environ.get("GRPC_MAX_WORKERS", "8")))

    port = os.environ.get("PORT", "8001")
    server.add_insecure_port(f"[::]:{port}")
    server.start()
    logger.info("Retrieval gRPC server listening on :%s", port)
    server.wait_for_termination()


if __name__ == "__main__":
    serve()

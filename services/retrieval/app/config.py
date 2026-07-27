"""Environment-driven configuration for the Retrieval Service."""

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    graph_path: str
    max_hops: int
    top_k: int
    max_ngram: int
    max_entities: int
    max_triples: int


def get_settings() -> Settings:
    return Settings(
        graph_path=os.environ.get("GRAPH_PATH", "/data/knowledge_graph_with_coref.graphml"),
        max_hops=int(os.environ.get("MAX_HOPS", "2")),
        top_k=int(os.environ.get("TOP_K", "20")),
        max_ngram=int(os.environ.get("MAX_NGRAM", "5")),
        max_entities=int(os.environ.get("MAX_ENTITIES", "3")),
        max_triples=int(os.environ.get("MAX_TRIPLES", "20")),
    )

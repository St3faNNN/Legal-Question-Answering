import json
import sqlite3
import struct

import networkx as nx
import numpy as np
import pytest

# --- Legacy entity-graph fixtures (graph.py / linker.py) ----------------------


@pytest.fixture()
def fixture_graph() -> nx.MultiGraph:
    """Small graph mirroring the old entity graph's shape (undirected
    multigraph, lowercase node ids, `relation` + `confidence` edge attrs)."""
    graph = nx.MultiGraph()
    for node in [
        "albert einstein",
        "theory of relativity",
        "ulm",
        "germany",
        "european union",
        "marie curie (physicist)",
        "isaac newton",
    ]:
        graph.add_node(node, label=node, type="entity")

    graph.add_edge("albert einstein", "theory of relativity", relation="developed", confidence=0.95)
    graph.add_edge("albert einstein", "ulm", relation="born_in", confidence=0.9)
    graph.add_edge("albert einstein", "isaac newton", relation="influenced_by", confidence=0.2)
    graph.add_edge("ulm", "germany", relation="located_in", confidence=0.8)
    # 3 hops from einstein: must be excluded at max_hops=2.
    graph.add_edge("germany", "european union", relation="member_of", confidence=0.7)
    graph.add_edge("marie curie (physicist)", "theory of relativity", relation="studied", confidence=0.5)
    return graph


@pytest.fixture()
def graphml_path(fixture_graph, tmp_path) -> str:
    path = tmp_path / "fixture_graph.graphml"
    nx.write_graphml(fixture_graph, path)
    return str(path)


# --- Corpus fixtures (vectors.sqlite + graph.graphml) -------------------------

#: 4-dimensional stand-ins for the real 1024-d BGE-M3 vectors: small enough to
#: reason about by hand, and the store reads its dimension from meta so nothing
#: depends on the real width.
FIXTURE_DIM = 4

#: (chunk id, unit_key, citation, text, dense vector, sparse weights)
FIXTURE_CHUNKS = [
    (1, "zakon-a:chlen-1", "ZAKON-A, чл. 1", "Работно време и распоред", [1.0, 0.0, 0.0, 0.0], {10: 0.9, 11: 0.4}),
    (2, "zakon-a:chlen-2", "ZAKON-A, чл. 2", "Годишен одмор", [0.0, 1.0, 0.0, 0.0], {12: 0.8}),
    (3, "zakon-a:chlen-3", "ZAKON-A, чл. 3", "Плата и надоместоци", [0.0, 0.0, 1.0, 0.0], {13: 0.7}),
    (4, "zakon-b:chlen-1", "ZAKON-B, чл. 1", "Јавни набавки, предмет", [0.0, 0.0, 0.0, 1.0], {14: 0.6}),
    (5, "zakon-b:chlen-2", "ZAKON-B, чл. 2", "Договор за јавна набавка", [0.6, 0.8, 0.0, 0.0], {10: 0.2, 14: 0.5}),
]

#: (source, target, confidence) — article 1 of zakon-a cross-references article 3.
FIXTURE_REFERENCES = [
    ("zakon-a:chlen-1", "zakon-a:chlen-3", 1.0),
    ("zakon-b:chlen-1", "zakon-b:chlen-2", 0.75),
]


def _pack(vector: list[float]) -> bytes:
    return struct.pack(f"<{len(vector)}f", *vector)


@pytest.fixture()
def vector_db_path(tmp_path) -> str:
    """A vectors.sqlite with the production schema and a hand-built corpus."""
    path = tmp_path / "vectors.sqlite"
    connection = sqlite3.connect(path)
    connection.executescript(
        """
        CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE chunks(id INTEGER PRIMARY KEY, slug TEXT, unit_key TEXT, article TEXT,
            stav TEXT, header TEXT, body TEXT, text TEXT, citation TEXT, page INT, split TEXT);
        CREATE TABLE vectors(chunk_id INTEGER PRIMARY KEY, dense BLOB, sparse TEXT);
        """
    )
    connection.executemany(
        "INSERT INTO meta(key, value) VALUES (?, ?)",
        [("embed_model", "BAAI/bge-m3"), ("dim", str(FIXTURE_DIM))],
    )
    for chunk_id, unit_key, citation, text, dense, sparse in FIXTURE_CHUNKS:
        slug = unit_key.split(":")[0]
        connection.execute(
            "INSERT INTO chunks(id, slug, unit_key, article, header, body, text, citation, page, split) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'article')",
            (chunk_id, slug, unit_key, unit_key.split("-")[-1], citation, text, text, citation),
        )
        connection.execute(
            "INSERT INTO vectors(chunk_id, dense, sparse) VALUES (?, ?, ?)",
            (chunk_id, _pack(dense), json.dumps({str(k): v for k, v in sparse.items()})),
        )
    connection.commit()
    connection.close()
    return str(path)


@pytest.fixture()
def citation_graph() -> nx.MultiDiGraph:
    """Mirrors the real graph: article nodes keyed by unit_key, directed
    cross-reference edges carrying `rel` and `confidence`."""
    graph = nx.MultiDiGraph()
    for _id, unit_key, citation, _text, _dense, _sparse in FIXTURE_CHUNKS:
        graph.add_node(unit_key, doc=unit_key.split(":")[0], citation=citation, heading=citation)
    for source, target, confidence in FIXTURE_REFERENCES:
        graph.add_edge(source, target, rel="internal", raw="членот 3", confidence=confidence)
    return graph


@pytest.fixture()
def citation_graphml_path(citation_graph, tmp_path) -> str:
    path = tmp_path / "graph.graphml"
    nx.write_graphml(citation_graph, path)
    return str(path)


class FakeEmbedder:
    """Returns a canned query encoding, so the fusion path is testable without
    downloading BGE-M3."""

    def __init__(self, dense: list[float] | None = None, sparse: dict[int, float] | None = None):
        self.dense = np.array(dense or [1.0, 0.0, 0.0, 0.0], dtype=np.float32)
        self.sparse = sparse if sparse is not None else {}
        self.loaded = True
        self.encoded: list[str] = []

    def encode(self, text: str):
        self.encoded.append(text)
        return self.dense, self.sparse



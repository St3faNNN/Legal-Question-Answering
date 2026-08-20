import networkx as nx
import pytest

from app.citations import (
    CitationGraphError,
    citation_of,
    expand,
    load_citation_graph,
    reference_triples,
)


def test_load_preserves_direction_and_attributes(citation_graphml_path):
    graph = load_citation_graph(citation_graphml_path)

    assert isinstance(graph, nx.MultiDiGraph)
    assert graph.number_of_nodes() == 5
    assert graph.number_of_edges() == 2
    assert graph.has_edge("zakon-a:chlen-1", "zakon-a:chlen-3")
    assert not graph.has_edge("zakon-a:chlen-3", "zakon-a:chlen-1")


def test_load_rejects_a_graph_without_confidence(tmp_path):
    """Without confidence the expansion ranking silently becomes arbitrary,
    so this must fail loudly at startup rather than degrade."""
    graph = nx.MultiDiGraph()
    graph.add_edge("a:chlen-1", "a:chlen-2", rel="internal")
    path = tmp_path / "no_confidence.graphml"
    nx.write_graphml(graph, path)

    with pytest.raises(CitationGraphError, match="confidence"):
        load_citation_graph(str(path))


def test_load_accepts_an_edgeless_graph(tmp_path):
    graph = nx.MultiDiGraph()
    graph.add_node("a:chlen-1", citation="A, чл. 1")
    path = tmp_path / "edgeless.graphml"
    nx.write_graphml(graph, path)

    assert load_citation_graph(str(path)).number_of_edges() == 0


def test_citation_of_returns_the_node_label(citation_graph):
    assert citation_of(citation_graph, "zakon-a:chlen-1") == "ZAKON-A, чл. 1"


def test_citation_of_falls_back_to_the_id_for_unknown_articles(citation_graph):
    assert citation_of(citation_graph, "nema:chlen-1") == "nema:chlen-1"


def test_expand_follows_outgoing_references(citation_graph):
    found = expand(citation_graph, ["zakon-a:chlen-1"])

    assert [item.unit_key for item in found] == ["zakon-a:chlen-3"]
    assert found[0].hops == 1
    assert found[0].confidence == 1.0
    assert found[0].source_unit == "zakon-a:chlen-1"


def test_expand_follows_incoming_references(citation_graph):
    """An article that cites the seed is as relevant as one the seed cites."""
    found = expand(citation_graph, ["zakon-a:chlen-3"])

    assert [item.unit_key for item in found] == ["zakon-a:chlen-1"]


def test_expand_excludes_the_seeds_themselves(citation_graph):
    found = expand(citation_graph, ["zakon-a:chlen-1", "zakon-a:chlen-3"])

    assert [item.unit_key for item in found] == []


def test_expand_respects_the_hop_limit(citation_graph):
    citation_graph.add_edge("zakon-a:chlen-3", "zakon-b:chlen-1", rel="internal", confidence=0.9)

    one_hop = expand(citation_graph, ["zakon-a:chlen-1"], max_hops=1)
    two_hops = expand(citation_graph, ["zakon-a:chlen-1"], max_hops=2)

    assert [item.unit_key for item in one_hop] == ["zakon-a:chlen-3"]
    assert [item.unit_key for item in two_hops] == ["zakon-a:chlen-3", "zakon-b:chlen-1"]
    assert [item.hops for item in two_hops] == [1, 2]


def test_expand_orders_by_hop_then_confidence(citation_graph):
    citation_graph.add_edge("zakon-a:chlen-1", "zakon-a:chlen-2", rel="internal", confidence=0.5)
    citation_graph.add_edge("zakon-a:chlen-1", "zakon-b:chlen-1", rel="internal", confidence=0.8)

    found = expand(citation_graph, ["zakon-a:chlen-1"])

    assert [item.unit_key for item in found] == [
        "zakon-a:chlen-3",  # 1.0
        "zakon-b:chlen-1",  # 0.8
        "zakon-a:chlen-2",  # 0.5
    ]


def test_expand_caps_at_the_limit(citation_graph):
    citation_graph.add_edge("zakon-a:chlen-1", "zakon-a:chlen-2", rel="internal", confidence=0.5)
    citation_graph.add_edge("zakon-a:chlen-1", "zakon-b:chlen-1", rel="internal", confidence=0.8)

    assert len(expand(citation_graph, ["zakon-a:chlen-1"], limit=2)) == 2


def test_expand_keeps_a_neighbours_highest_confidence(citation_graph):
    """Parallel edges between the same pair are one relation at its best score."""
    citation_graph.add_edge("zakon-a:chlen-1", "zakon-a:chlen-3", rel="internal", confidence=0.3)

    found = expand(citation_graph, ["zakon-a:chlen-1"])

    assert len(found) == 1
    assert found[0].confidence == 1.0


def test_expand_with_unknown_seeds_returns_nothing(citation_graph):
    assert expand(citation_graph, ["nema:chlen-1"]) == []


def test_expand_with_no_seeds_returns_nothing(citation_graph):
    assert expand(citation_graph, []) == []


def test_reference_triples_render_citations(citation_graph):
    triples = reference_triples(citation_graph, ["zakon-a:chlen-1"], limit=5)

    assert triples == [
        {
            "head": "ZAKON-A, чл. 1",
            "relation": "упатува на",
            "tail": "ZAKON-A, чл. 3",
            "confidence": 1.0,
        }
    ]


def test_reference_triples_rank_by_confidence_and_cap(citation_graph):
    citation_graph.add_edge("zakon-a:chlen-2", "zakon-a:chlen-3", rel="internal", confidence=0.4)
    citation_graph.add_edge("zakon-b:chlen-2", "zakon-b:chlen-1", rel="internal", confidence=0.9)

    triples = reference_triples(
        citation_graph,
        ["zakon-a:chlen-1", "zakon-a:chlen-2", "zakon-b:chlen-2"],
        limit=2,
    )

    assert [triple["confidence"] for triple in triples] == [1.0, 0.9]


def test_reference_triples_ignore_articles_outside_the_graph(citation_graph):
    assert reference_triples(citation_graph, ["nema:chlen-1"], limit=5) == []


def test_reference_triples_with_zero_limit(citation_graph):
    assert reference_triples(citation_graph, ["zakon-a:chlen-1"], limit=0) == []

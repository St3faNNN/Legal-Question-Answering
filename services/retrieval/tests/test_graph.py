import networkx as nx

from app.graph import (
    extract_subgraph,
    linearize_subgraph_triples,
    linearize_triples,
    load_graph,
    merge_subgraphs,
    ranked_triples,
    subgraph_triples,
    top_k_confident_edges,
)


def test_load_graph_roundtrips_graphml(graphml_path):
    graph = load_graph(graphml_path)
    assert isinstance(graph, nx.MultiGraph)
    assert graph.number_of_nodes() == 7
    assert graph.number_of_edges() == 6


def test_top_k_edges_sorted_by_confidence_desc(fixture_graph):
    edges = top_k_confident_edges(fixture_graph, "albert einstein", k=2)
    assert [e[3] for e in edges] == [0.95, 0.9]


def test_top_k_edges_missing_node(fixture_graph):
    assert top_k_confident_edges(fixture_graph, "nope", k=5) == []


def test_extract_subgraph_respects_hop_limit(fixture_graph):
    subgraph = extract_subgraph("albert einstein", fixture_graph, max_hops=2, top_k=20)
    assert "germany" in subgraph  # 2 hops away
    assert "european union" not in subgraph  # 3 hops away


def test_extract_subgraph_unknown_entity_is_empty(fixture_graph):
    subgraph = extract_subgraph("unknown", fixture_graph)
    assert subgraph.number_of_nodes() == 0
    assert extract_subgraph(None, fixture_graph).number_of_nodes() == 0


def test_extract_subgraph_top_k_truncates(fixture_graph):
    subgraph = extract_subgraph("albert einstein", fixture_graph, max_hops=1, top_k=1)
    assert subgraph.number_of_edges() == 1
    assert "theory of relativity" in subgraph  # highest-confidence edge kept


def test_linearize_format(fixture_graph):
    subgraph = extract_subgraph("ulm", fixture_graph, max_hops=1, top_k=20)
    context = linearize_subgraph_triples(subgraph)
    parts = context.split(" | ")
    assert "ulm located_in germany" in parts or "germany located_in ulm" in parts
    assert len(parts) == subgraph.number_of_edges()


def test_subgraph_triples_shape(fixture_graph):
    subgraph = extract_subgraph("ulm", fixture_graph, max_hops=1, top_k=20)
    triples = subgraph_triples(subgraph)
    assert all({"head", "relation", "tail", "confidence"} == set(t) for t in triples)
    assert any(t["relation"] == "located_in" and t["confidence"] == 0.8 for t in triples)


def test_ranked_triples_dedupes_and_keeps_highest_confidence(fixture_graph):
    # A duplicate parallel edge (as produced by multiple source articles).
    fixture_graph.add_edge("albert einstein", "ulm", relation="born_in", confidence=0.4)
    subgraph = extract_subgraph("albert einstein", fixture_graph, max_hops=1, top_k=20)
    triples = ranked_triples(subgraph, ["albert einstein"], limit=20)
    born_in = [t for t in triples if t["relation"] == "born_in"]
    assert len(born_in) == 1
    assert born_in[0]["confidence"] == 0.9


def test_ranked_triples_prefers_anchor_edges_then_confidence(fixture_graph):
    subgraph = extract_subgraph("albert einstein", fixture_graph, max_hops=2, top_k=20)
    triples = ranked_triples(subgraph, ["albert einstein"], limit=20)
    anchored = [t["head"] == "albert einstein" or t["tail"] == "albert einstein" for t in triples]
    # All anchor-incident triples come before any non-anchor triple.
    assert anchored == sorted(anchored, reverse=True)
    anchor_confidences = [t["confidence"] for t in triples if t["head"] == "albert einstein" or t["tail"] == "albert einstein"]
    assert anchor_confidences == sorted(anchor_confidences, reverse=True)


def test_ranked_triples_respects_limit(fixture_graph):
    subgraph = extract_subgraph("albert einstein", fixture_graph, max_hops=2, top_k=20)
    assert len(ranked_triples(subgraph, ["albert einstein"], limit=2)) == 2


def test_linearize_triples_format():
    triples = [
        {"head": "a", "relation": "r1", "tail": "b", "confidence": 0.9},
        {"head": "b", "relation": "r2", "tail": "c", "confidence": 0.8},
    ]
    assert linearize_triples(triples) == "a r1 b | b r2 c"
    assert linearize_triples([]) == ""


def test_merge_subgraphs(fixture_graph):
    a = extract_subgraph("ulm", fixture_graph, max_hops=1, top_k=20)
    b = extract_subgraph("marie curie (physicist)", fixture_graph, max_hops=1, top_k=20)
    merged = merge_subgraphs([a, b])
    assert set(a.nodes()) | set(b.nodes()) == set(merged.nodes())
    assert merge_subgraphs([]).number_of_nodes() == 0

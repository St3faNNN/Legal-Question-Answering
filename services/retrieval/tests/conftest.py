import networkx as nx
import pytest


@pytest.fixture()
def fixture_graph() -> nx.MultiGraph:
    """Small graph mirroring the real one's shape (undirected multigraph,
    lowercase node ids, `relation` + `confidence` edge attrs)."""
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

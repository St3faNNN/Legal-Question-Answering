"""Knowledge-graph loading, subgraph extraction, and triple linearization.

`top_k_confident_edges`, `extract_subgraph`, and `linearize_subgraph_triples`
are verbatim ports of the reference notebook (`bart_eval_with_coref.ipynb`);
`extract_subgraph` takes an already-matched node id because entity matching
happens in the linker.
"""

from collections import deque

import networkx as nx


def load_graph(path: str) -> nx.MultiGraph:
    graph = nx.read_graphml(path)
    # The notebook treats the graph as an undirected multigraph; coerce so the
    # neighbor/edge iteration below behaves identically regardless of how the
    # GraphML declares itself.
    return nx.MultiGraph(graph)


def top_k_confident_edges(
    graph: nx.MultiGraph, node: str, k: int = 20
) -> list[tuple[str, str, dict, float]]:
    if node not in graph:
        return []
    edges = []
    for neighbor in graph.neighbors(node):
        for _key, data in graph[node][neighbor].items():
            confidence = float(data.get("confidence", 0.0))
            edges.append((node, neighbor, data, confidence))
    edges.sort(key=lambda edge: edge[3], reverse=True)
    return edges[:k]


def extract_subgraph(
    node_id: str | None, graph: nx.MultiGraph, max_hops: int = 2, top_k: int = 20
) -> nx.MultiGraph:
    subgraph = nx.MultiGraph()
    if node_id is None or node_id not in graph:
        return subgraph

    visited = {node_id}
    queue: deque[tuple[str, int]] = deque([(node_id, 0)])
    while queue:
        current, hop = queue.popleft()
        if hop >= max_hops:
            continue
        for u, v, data, _confidence in top_k_confident_edges(graph, current, k=top_k):
            if u not in subgraph:
                subgraph.add_node(u, **dict(graph.nodes[u]))
            if v not in subgraph:
                subgraph.add_node(v, **dict(graph.nodes[v]))
            subgraph.add_edge(u, v, **data)
            if v not in visited:
                visited.add(v)
                queue.append((v, hop + 1))
    return subgraph


def merge_subgraphs(subgraphs: list[nx.MultiGraph]) -> nx.MultiGraph:
    if not subgraphs:
        return nx.MultiGraph()
    return nx.compose_all(subgraphs)


def linearize_subgraph_triples(subgraph: nx.MultiGraph) -> str:
    triples = []
    for u, v, data in subgraph.edges(data=True):
        relation = data.get("relation", "related_to")
        triples.append(f"{u} {relation} {v}")
    return " | ".join(triples)


def subgraph_triples(subgraph: nx.MultiGraph) -> list[dict]:
    return [
        {
            "head": u,
            "relation": data.get("relation", "related_to"),
            "tail": v,
            "confidence": float(data.get("confidence", 0.0)),
        }
        for u, v, data in subgraph.edges(data=True)
    ]


def ranked_triples(subgraph: nx.MultiGraph, anchor_nodes: list[str], limit: int) -> list[dict]:
    """Dedupe and rank a subgraph's triples for use as generation context.

    The raw 2-hop expansion contains many duplicate parallel edges (one per
    source article) and neighbor-of-neighbor facts that drown out the entity
    the question is about. Keep each (head, relation, tail) once at its highest
    confidence, rank edges touching a linked (anchor) entity above the rest,
    then by confidence, and cap the total.
    """
    anchors = set(anchor_nodes)
    best: dict[tuple[str, str, str], dict] = {}
    for triple in subgraph_triples(subgraph):
        key = (triple["head"], triple["relation"], triple["tail"])
        if key not in best or triple["confidence"] > best[key]["confidence"]:
            best[key] = triple
    ranked = sorted(
        best.values(),
        key=lambda t: (
            t["head"] not in anchors and t["tail"] not in anchors,
            -t["confidence"],
        ),
    )
    return ranked[:limit]


def linearize_triples(triples: list[dict]) -> str:
    return " | ".join(f"{t['head']} {t['relation']} {t['tail']}" for t in triples)

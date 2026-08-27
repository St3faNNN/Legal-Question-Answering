"""The graph half of retrieval: expansion over the corpus citation graph.

Nodes in graph.graphml are legal articles keyed by the same `unit_key` the
vector store uses, and edges are the cross-references one article makes to
another ("членовите 11, 12, 13 ... од овој закон"). So the graph does not answer
a question on its own — it answers "given these articles, what else must be read
with them", which is exactly the failure mode of pure vector search over
legislation: an article's meaning is routinely qualified by another article it
points at.

Expansion is therefore seeded from the vector hits rather than from entity
linking. The graph is directed, but references are followed in both directions:
an article that cites the seed is as relevant as one the seed cites.
"""

import logging
from collections import deque
from dataclasses import dataclass

import networkx as nx

logger = logging.getLogger("retrieval")

REFERENCE_RELATION = "упатува на"


class CitationGraphError(RuntimeError):
    """The graph is missing the attributes retrieval depends on."""


@dataclass(frozen=True)
class Expansion:
    unit_key: str
    hops: int
    confidence: float
    source_unit: str


def load_citation_graph(path: str) -> nx.MultiDiGraph:
    """Loads graph.graphml as a directed multigraph and asserts the edge
    attributes retrieval ranks on are present.

    Without this check a graph missing `confidence` would silently rank every
    expansion at 0.0 and degrade to arbitrary order rather than failing.
    """
    graph = nx.MultiDiGraph(nx.read_graphml(path))

    if graph.number_of_edges() and not any(
        "confidence" in data for _u, _v, data in graph.edges(data=True)
    ):
        raise CitationGraphError(
            f"citation graph at {path} has no edge carrying a 'confidence' attribute; "
            "expansion ranking would be arbitrary"
        )
    return graph


def citation_of(graph: nx.MultiDiGraph, unit_key: str) -> str:
    """The human-readable citation for an article node, e.g. 'ZAKON-ZA-JAVNITE, чл. 1'."""
    if unit_key not in graph:
        return unit_key
    data = graph.nodes[unit_key]
    return data.get("citation") or unit_key


def resolve_unit(graph: nx.MultiDiGraph, law: str, article: str) -> str | None:
    """Resolve a (law, article) pair to a graph node id.

    `law` is matched against the node's short document code (the ``doc``
    attribute, e.g. "ZAKON-ZA-JAVNITE" — the part before the comma in a
    citation), case-insensitively; `article` against the ``article`` attribute.
    Article numbers repeat across laws, so both must match. Returns None when
    nothing matches, so the caller can report "not found" rather than guess.
    """
    law_norm = law.strip().upper()
    article_norm = article.strip().lstrip("чл.").strip()
    if not law_norm or not article_norm:
        return None

    for node, data in graph.nodes(data=True):
        # Prefer the node's own `article` attribute; fall back to the number in
        # the canonical unit_key ("{slug}:chlen-{number}") when it is absent.
        node_article = str(data.get("article", "")).strip() or _article_from_key(node)
        if node_article != article_norm:
            continue
        doc = str(data.get("doc", "")).strip().upper()
        citation = str(data.get("citation", "")).strip().upper()
        if doc == law_norm or citation.startswith(law_norm):
            return node

    return None


def _article_from_key(unit_key: str) -> str:
    marker = ":chlen-"
    index = unit_key.rfind(marker)

    return unit_key[index + len(marker):] if index != -1 else ""


def outgoing_references(graph: nx.MultiDiGraph, unit_key: str, limit: int = 50) -> list[dict]:
    """The articles `unit_key` cites — its outgoing citation edges only.

    Identifier-only by design: each row is the target's citation, its heading
    (a short label of what the article is about, not its body text) and the
    parsed cross-reference confidence. Deduped to the strongest edge per target,
    strongest first.
    """
    if unit_key not in graph or limit <= 0:
        return []

    best: dict[str, float] = {}
    for _source, target, data in graph.out_edges(unit_key, data=True):
        confidence = float(data.get("confidence", 0.0))
        if confidence > best.get(target, -1.0):
            best[target] = confidence

    ranked = sorted(best.items(), key=lambda item: -item[1])[:limit]
    return [
        {
            "citation": citation_of(graph, target),
            "heading": str(graph.nodes[target].get("heading", "")),
            "confidence": confidence,
        }
        for target, confidence in ranked
    ]


def expand(
    graph: nx.MultiDiGraph,
    seed_units: list[str],
    max_hops: int = 1,
    limit: int = 20,
) -> list[Expansion]:
    """Articles reachable from the seeds by cross-reference, nearest hop first
    and highest confidence first within a hop, excluding the seeds themselves."""
    if not seed_units or limit <= 0:
        return []

    seeds = {unit for unit in seed_units if unit in graph}
    visited = set(seeds)
    found: list[Expansion] = []
    queue: deque[tuple[str, int]] = deque((unit, 0) for unit in seeds)

    while queue:
        current, hops = queue.popleft()
        if hops >= max_hops:
            continue

        for neighbour, confidence in _neighbours(graph, current):
            if neighbour in visited:
                continue
            visited.add(neighbour)
            found.append(
                Expansion(
                    unit_key=neighbour,
                    hops=hops + 1,
                    confidence=confidence,
                    source_unit=current,
                )
            )
            queue.append((neighbour, hops + 1))

    found.sort(key=lambda item: (item.hops, -item.confidence))
    return found[:limit]


def _neighbours(graph: nx.MultiDiGraph, unit_key: str) -> list[tuple[str, float]]:
    """Both directions of the citation relation, each neighbour at its highest
    edge confidence, ordered strongest first."""
    best: dict[str, float] = {}

    for _source, target, data in graph.out_edges(unit_key, data=True):
        confidence = float(data.get("confidence", 0.0))
        best[target] = max(best.get(target, 0.0), confidence)
    for source, _target, data in graph.in_edges(unit_key, data=True):
        confidence = float(data.get("confidence", 0.0))
        best[source] = max(best.get(source, 0.0), confidence)

    best.pop(unit_key, None)
    return sorted(best.items(), key=lambda item: -item[1])


def reference_triples(
    graph: nx.MultiDiGraph,
    unit_keys: list[str],
    limit: int,
) -> list[dict]:
    """The cross-references among a set of articles, as head/relation/tail rows.

    This is what the UI's knowledge-graph citation list renders: which retrieved
    article points at which other one, in the corpus's own citation vocabulary.
    """
    if not unit_keys or limit <= 0:
        return []

    wanted = [unit for unit in unit_keys if unit in graph]
    best: dict[tuple[str, str], float] = {}

    for unit in wanted:
        for _source, target, data in graph.out_edges(unit, data=True):
            key = (unit, target)
            confidence = float(data.get("confidence", 0.0))
            if confidence > best.get(key, -1.0):
                best[key] = confidence

    ranked = sorted(best.items(), key=lambda item: -item[1])[:limit]
    return [
        {
            "head": citation_of(graph, source),
            "relation": REFERENCE_RELATION,
            "tail": citation_of(graph, target),
            "confidence": confidence,
        }
        for (source, target), confidence in ranked
    ]

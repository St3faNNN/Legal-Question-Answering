"""Rank fusion and context packing.

Reciprocal Rank Fusion is used rather than a weighted score sum because the
three signals are not on a shared scale: dense cosine sits in [-1, 1], BGE-M3
lexical dot products are unbounded positives, and graph expansion has no
similarity score at all — only a hop distance. Rank position is the one thing
all three agree on.
"""

from dataclasses import dataclass, field


@dataclass
class Candidate:
    """One retrievable unit, whatever it was found by."""

    key: str
    text: str
    unit_key: str
    citation: str
    chunk_id: int
    sources: list[str] = field(default_factory=list)
    fused_score: float = 0.0
    rerank_score: float | None = None


def reciprocal_rank_fusion(
    rankings: dict[str, list[Candidate]],
    k: int = 60,
    limit: int | None = None,
) -> list[Candidate]:
    """Merges named rankings into one, scoring each candidate `sum(1 / (k + rank))`.

    Candidates appearing in several rankings accumulate, which is the point: an
    article that is both semantically close and lexically matched should outrank
    one that is only either.
    """
    merged: dict[str, Candidate] = {}

    for source, ranking in rankings.items():
        for rank, candidate in enumerate(ranking, start=1):
            existing = merged.get(candidate.key)
            if existing is None:
                existing = Candidate(
                    key=candidate.key,
                    text=candidate.text,
                    unit_key=candidate.unit_key,
                    citation=candidate.citation,
                    chunk_id=candidate.chunk_id,
                )
                merged[candidate.key] = existing
            existing.sources.append(source)
            existing.fused_score += 1.0 / (k + rank)

    ordered = sorted(merged.values(), key=lambda item: -item.fused_score)
    return ordered if limit is None else ordered[:limit]


def pack_context(candidates: list[Candidate], char_budget: int) -> tuple[str, int]:
    """Joins candidates in rank order until the budget is spent.

    Returns the context string and how many candidates actually made it in. That
    count is reported all the way to the message metadata, so the gap between
    "reranked to 10" and "the generator saw 4" is visible rather than silently
    absorbed by the generator's own truncation.
    """
    if not candidates or char_budget <= 0:
        return "", 0

    parts: list[str] = []
    used = 0
    separator_cost = len(CONTEXT_SEPARATOR)

    for candidate in candidates:
        block = _block(candidate)
        cost = len(block) + (separator_cost if parts else 0)
        if used + cost > char_budget:
            # Keep scanning: a later, shorter candidate may still fit, and
            # dropping it would lose a citation the reranker ranked highly.
            continue
        parts.append(block)
        used += cost

    return CONTEXT_SEPARATOR.join(parts), len(parts)


CONTEXT_SEPARATOR = "\n\n"


def _block(candidate: Candidate) -> str:
    """A context block leads with its citation so the generator can attribute
    the statement it is quoting."""
    if candidate.citation and not candidate.text.startswith(candidate.citation):
        return f"[{candidate.citation}]\n{candidate.text}"
    return candidate.text

"""Ranking and window behaviour, now that RRF is the only ordering.

Rehomed from ``test_reranker_backend.py``, which was deleted with the
cross-encoder. Five of its ten tests were never about the reranker: three assert
what the retrieve path returns (RRF order, graph arm, the RETRIEVAL_TOP_N
window), and two assert the pool-vs-window startup check. Those invariants
outlived the reranker, so they moved here rather than going with it.

The five that did go were about backend selection and construction:
``test_none_backend_builds_no_reranker``,
``test_none_backend_never_constructs_a_cross_encoder``,
``test_torch_backend_constructs_but_does_not_load``,
``test_onnx_backend_constructs_but_does_not_load`` and
``test_unknown_backend_fails_loudly``. There is no backend to select.
"""

import pytest
from app.config import get_settings
from app.main import RetrievalContext
from app.vectors import VectorStore
from app.citations import load_citation_graph

from conftest import FakeEmbedder


def _context(vector_db_path, citation_graphml_path, embedder=None) -> RetrievalContext:
    store = VectorStore(vector_db_path)
    store.load()

    return RetrievalContext(
        get_settings(),
        store,
        load_citation_graph(citation_graphml_path),
        embedder or FakeEmbedder(dense=[1.0, 0.0, 0.0, 0.0]),
    )


def test_results_come_back_in_rrf_order(vector_db_path, citation_graphml_path):
    context = _context(vector_db_path, citation_graphml_path)

    result = context.retrieve("Работно време?")

    assert len(result["chunks"]) > 0
    # The fused score is the only score there is now, and the returned order is
    # descending fused_score — the RRF ordering, untouched.
    scores = [chunk["score"] for chunk in result["chunks"]]
    assert scores == sorted(scores, reverse=True)


def test_the_graph_arm_and_context_packing_still_run(vector_db_path, citation_graphml_path):
    context = _context(vector_db_path, citation_graphml_path)

    result = context.retrieve("Работно време?")

    sources = {chunk["source"] for chunk in result["chunks"]}
    assert "ZAKON-A, чл. 1" in sources  # direct vector hit
    assert "ZAKON-A, чл. 3" in sources  # reached only via the citation graph
    assert result["context"]
    assert result["context_items"] > 0


def test_the_window_caps_at_retrieval_top_n(vector_db_path, citation_graphml_path, monkeypatch):
    monkeypatch.setenv("RETRIEVAL_TOP_N", "2")
    context = _context(vector_db_path, citation_graphml_path)

    result = context.retrieve("Работно време?")

    assert len(result["chunks"]) == 2


def test_check_config_warns_when_window_exceeds_pool(monkeypatch, caplog):
    """RETRIEVAL_TOP_N > CANDIDATE_POOL degrades silently (the pool caps the
    result); build_context must surface it as a startup warning."""
    from app.main import _check_config

    monkeypatch.setenv("CANDIDATE_POOL", "6")
    monkeypatch.setenv("RETRIEVAL_TOP_N", "10")
    with caplog.at_level("WARNING", logger="retrieval"):
        _check_config(get_settings())

    assert any("exceeds CANDIDATE_POOL" in r.message for r in caplog.records)


def test_check_config_is_quiet_when_pool_covers_the_window(monkeypatch, caplog):
    from app.main import _check_config

    monkeypatch.setenv("CANDIDATE_POOL", "24")
    monkeypatch.setenv("RETRIEVAL_TOP_N", "10")
    with caplog.at_level("WARNING", logger="retrieval"):
        _check_config(get_settings())

    assert not any("exceeds CANDIDATE_POOL" in r.message for r in caplog.records)


def test_no_cross_encoder_module_remains():
    """The path is removed, not switched off: importing it must fail."""
    with pytest.raises(ImportError):
        import app.reranker  # noqa: F401

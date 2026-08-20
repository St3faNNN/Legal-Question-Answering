"""RERANK_BACKEND selection and the RERANK_BACKEND=none fall-through.

`none` is the default: the cross-encoder must not be fetched, loaded, or held,
and ordering must fall through to the RRF-fused pool. torch/onnx stay available
for the eval harness. These run offline — no weights are downloaded (a backend
is *constructed* but never `.load()`ed, and the none path constructs nothing)."""

import pytest

from app.citations import load_citation_graph
from app.config import get_settings
from app.main import RetrievalContext, _build_reranker
from app.vectors import VectorStore
from tests.conftest import FakeEmbedder


@pytest.fixture(autouse=True)
def clean_backend_env(monkeypatch):
    monkeypatch.delenv("RERANK_BACKEND", raising=False)


# --- backend selection --------------------------------------------------------


def test_none_backend_builds_no_reranker(monkeypatch):
    monkeypatch.setenv("RERANK_BACKEND", "none")
    assert _build_reranker(get_settings()) is None


def test_none_backend_never_constructs_a_cross_encoder(monkeypatch):
    """Stronger than checking the return value: prove neither backend class is
    even instantiated, so nothing reaches out for a model."""
    import app.reranker as reranker_mod

    def boom(*args, **kwargs):
        raise AssertionError("a reranker must not be constructed when RERANK_BACKEND=none")

    monkeypatch.setattr(reranker_mod, "CrossEncoderReranker", boom)
    monkeypatch.setattr(reranker_mod, "OnnxCrossEncoderReranker", boom)
    monkeypatch.setenv("RERANK_BACKEND", "none")

    assert _build_reranker(get_settings()) is None


def test_torch_backend_constructs_but_does_not_load(monkeypatch):
    from app.reranker import CrossEncoderReranker

    monkeypatch.setenv("RERANK_BACKEND", "torch")
    reranker = _build_reranker(get_settings())

    assert isinstance(reranker, CrossEncoderReranker)
    assert reranker.loaded is False  # constructed only — no weights fetched


def test_onnx_backend_constructs_but_does_not_load(monkeypatch):
    from app.reranker import OnnxCrossEncoderReranker

    monkeypatch.setenv("RERANK_BACKEND", "onnx")
    reranker = _build_reranker(get_settings())

    assert isinstance(reranker, OnnxCrossEncoderReranker)
    assert reranker.loaded is False  # onnxruntime is imported only in load()


def test_unknown_backend_fails_loudly(monkeypatch):
    monkeypatch.setenv("RERANK_BACKEND", "bogus")
    with pytest.raises(ValueError, match="Unknown RERANK_BACKEND"):
        _build_reranker(get_settings())


# --- none fall-through behaviour ----------------------------------------------


def _context_without_reranker(vector_db_path, citation_graphml_path, embedder=None):
    store = VectorStore(vector_db_path)
    store.load()
    return RetrievalContext(
        get_settings(),
        store,
        load_citation_graph(citation_graphml_path),
        embedder or FakeEmbedder(dense=[1.0, 0.0, 0.0, 0.0]),
        reranker=None,
    )


def test_disabled_reranker_returns_the_rrf_order(vector_db_path, citation_graphml_path):
    context = _context_without_reranker(vector_db_path, citation_graphml_path)

    result = context.retrieve("Работно време?")

    assert len(result["chunks"]) > 0
    # With no cross-encoder the fused score surfaces, and the RRF ordering (which
    # is descending fused_score) is preserved untouched.
    scores = [chunk["score"] for chunk in result["chunks"]]
    assert scores == sorted(scores, reverse=True)


def test_disabled_reranker_still_produces_a_valid_response(vector_db_path, citation_graphml_path):
    """The graph arm and context packing still run; only reranking is skipped."""
    context = _context_without_reranker(vector_db_path, citation_graphml_path)

    result = context.retrieve("Работно време?")

    sources = {chunk["source"] for chunk in result["chunks"]}
    assert "ZAKON-A, чл. 1" in sources  # direct vector hit
    assert "ZAKON-A, чл. 3" in sources  # reached only via the citation graph
    assert result["context"]
    assert result["context_items"] > 0


def test_disabled_reranker_caps_at_retrieval_top_n(vector_db_path, citation_graphml_path, monkeypatch):
    monkeypatch.setenv("RETRIEVAL_TOP_N", "2")
    context = _context_without_reranker(vector_db_path, citation_graphml_path)

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

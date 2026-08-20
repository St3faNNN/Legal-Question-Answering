"""Defaults that are load-bearing for query latency.

These are not style preferences. Each one was measured: the combination below is
the difference between a query answering in seconds and one exceeding a ten
minute deadline on CPU.
"""

import pytest

from app.config import get_settings


@pytest.fixture(autouse=True)
def clean_env(monkeypatch):
    for key in [
        "MODEL_DTYPE",
        "QUANTIZE",
        "RERANK_BACKEND",
        "CANDIDATE_POOL",
        "RETRIEVAL_TOP_N",
        "RERANK_TOP_N",
        "RERANK_MAX_LENGTH",
        "DENSE_TOP_K",
        "USE_SPARSE",
    ]:
        monkeypatch.delenv(key, raising=False)


def test_dtype_defaults_to_float32_because_cpu_bfloat16_is_emulated():
    """PyTorch has no fast CPU path for bfloat16 without AMX. Loading the
    cross-encoder at bfloat16 took a single query past ten minutes."""
    assert get_settings().dtype == "float32"


def test_candidate_pool_covers_the_returned_window():
    """The returned window is sliced from the fused pool, so the pool must be at
    least as large as RETRIEVAL_TOP_N or it caps the result."""
    settings = get_settings()

    assert settings.candidate_pool <= 24
    assert settings.candidate_pool >= settings.retrieval_top_n


def test_rerank_sequence_length_is_bounded():
    """Cost is roughly linear in sequence length; chunks average ~300 tokens."""
    assert get_settings().rerank_max_length <= 256


def test_retrieval_top_n_is_the_requested_ten():
    assert get_settings().retrieval_top_n == 10


def test_rerank_top_n_is_honoured_as_a_deprecated_alias(monkeypatch):
    """Existing .env files that still set RERANK_TOP_N must not break silently."""
    monkeypatch.setenv("RERANK_TOP_N", "7")
    assert get_settings().retrieval_top_n == 7


def test_retrieval_top_n_takes_precedence_over_the_alias(monkeypatch):
    monkeypatch.setenv("RETRIEVAL_TOP_N", "12")
    monkeypatch.setenv("RERANK_TOP_N", "7")
    assert get_settings().retrieval_top_n == 12


def test_empty_top_n_env_falls_back_to_default(monkeypatch):
    """docker-compose passes an empty string for an unset ${VAR:-}; treat it as unset."""
    monkeypatch.setenv("RETRIEVAL_TOP_N", "")
    monkeypatch.setenv("RERANK_TOP_N", "")
    assert get_settings().retrieval_top_n == 10


def test_quantization_is_off_by_default_so_local_dev_matches_production_weights():
    """int8 is a deployment memory optimization; local dev and the test doubles
    run the encoders unquantized."""
    assert get_settings().quantize == "none"


def test_rerank_backend_defaults_to_none_so_the_cross_encoder_is_not_loaded():
    """The cross-encoder is out of the default load path: it cost ~1.5 GiB for a
    recall@6 gain the product does not need (practitioners read the cited
    provisions themselves). torch/onnx remain available for the eval harness."""
    settings = get_settings()

    assert settings.rerank_backend == "none"
    # The onnx source is configured but only consulted when the backend is onnx.
    assert settings.rerank_onnx_repo == "onnx-community/bge-reranker-v2-m3-ONNX"
    assert settings.rerank_onnx_file == "onnx/model_int8.onnx"


def test_rerank_backend_is_overridable_to_torch_or_onnx(monkeypatch):
    monkeypatch.setenv("RERANK_BACKEND", "onnx")
    assert get_settings().rerank_backend == "onnx"
    monkeypatch.setenv("RERANK_BACKEND", "torch")
    assert get_settings().rerank_backend == "torch"


def test_every_latency_default_is_overridable(monkeypatch):
    monkeypatch.setenv("MODEL_DTYPE", "bfloat16")
    monkeypatch.setenv("QUANTIZE", "int8")
    monkeypatch.setenv("CANDIDATE_POOL", "50")
    monkeypatch.setenv("RERANK_MAX_LENGTH", "512")
    settings = get_settings()

    assert settings.dtype == "bfloat16"
    assert settings.quantize == "int8"
    assert settings.candidate_pool == 50
    assert settings.rerank_max_length == 512


def test_sparse_arm_is_on_by_default():
    assert get_settings().use_sparse is True

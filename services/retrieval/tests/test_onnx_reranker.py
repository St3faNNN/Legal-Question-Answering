"""Fail-loud behaviour of the ONNX reranker backend.

The reranker module imports torch at module scope, which the lean test venv does
not have, so these are skipped there and run wherever torch is present (inside
the retrieval image). They assert the backend never degrades silently.
"""

import pytest

pytest.importorskip("torch")

from app.reranker import OnnxCrossEncoderReranker  # noqa: E402  (after importorskip)


def test_load_fails_loudly_when_the_model_cannot_be_resolved():
    """Missing onnxruntime or an unfetchable/unopenable model must raise at load
    time (a clear RuntimeError), never fall back to the torch path silently."""
    reranker = OnnxCrossEncoderReranker(
        "BAAI/bge-reranker-v2-m3",
        "onnx-community/this-repo-does-not-exist-xyz",
        "onnx/model_int8.onnx",
    )

    with pytest.raises(RuntimeError):
        reranker.load()


def test_score_before_load_raises():
    reranker = OnnxCrossEncoderReranker("BAAI/bge-reranker-v2-m3", "repo", "file.onnx")

    with pytest.raises(RuntimeError, match="not loaded"):
        reranker.score("question", ["passage"])

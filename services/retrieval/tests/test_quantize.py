"""Unit tests for the optional int8 quantization helper.

Torch is not installed in the lean test environment (the retrieval suite runs
against fakes), so these are skipped there and run wherever torch is present —
inside the service image, or a full local venv.
"""

import pytest

torch = pytest.importorskip("torch")

from app.dtypes import maybe_quantize  # noqa: E402  (after importorskip)


def test_none_returns_the_same_object():
    linear = torch.nn.Linear(8, 8)
    assert maybe_quantize(linear, "none", "cpu") is linear


def test_int8_quantizes_linear_weights_to_qint8():
    torch.manual_seed(0)
    model = torch.nn.Sequential(
        torch.nn.Linear(32, 32), torch.nn.ReLU(), torch.nn.Linear(32, 4)
    ).eval()

    quantized = maybe_quantize(model, "int8", "cpu")

    # The Linear became a dynamic-quantized module storing int8 weights.
    assert "quantized" in type(quantized[0]).__module__
    assert quantized[0].weight().dtype == torch.qint8


def test_int8_preserves_output_shape_and_finiteness():
    torch.manual_seed(0)
    model = torch.nn.Sequential(torch.nn.Linear(32, 32), torch.nn.ReLU(), torch.nn.Linear(32, 4)).eval()
    x = torch.randn(3, 32)
    with torch.no_grad():
        reference = model(x)
        out = maybe_quantize(model, "int8", "cpu")(x)

    assert out.shape == reference.shape
    assert torch.isfinite(out).all()


def test_int8_is_skipped_on_non_cpu_device():
    """The dynamic int8 kernels are CPU-only, so a GPU host is left untouched."""
    linear = torch.nn.Linear(8, 8)
    assert maybe_quantize(linear, "int8", "cuda") is linear


def test_unknown_mode_raises():
    with pytest.raises(ValueError):
        maybe_quantize(torch.nn.Linear(8, 8), "int4", "cpu")

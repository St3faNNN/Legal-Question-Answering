import pytest
import torch

from app.config import get_settings
from app.dtypes import resolve


def test_auto_is_passed_through_for_transformers_to_interpret():
    assert resolve("auto") == "auto"


@pytest.mark.parametrize(
    ("name", "expected"),
    [("float32", torch.float32), ("float16", torch.float16), ("bfloat16", torch.bfloat16)],
)
def test_named_dtypes_resolve(name, expected):
    assert resolve(name) is expected


def test_unknown_dtype_raises_rather_than_silently_defaulting():
    with pytest.raises(ValueError, match="Unknown MODEL_DTYPE"):
        resolve("float8")


def test_default_is_float32_not_auto(monkeypatch):
    """"auto" would take Qwen2.5's declared bfloat16, which PyTorch emulates on a
    non-AMX CPU — measured at over 400s for one short-context answer."""
    monkeypatch.delenv("MODEL_DTYPE", raising=False)

    assert get_settings().dtype == "float32"


def test_setting_is_env_driven(monkeypatch):
    monkeypatch.setenv("MODEL_DTYPE", "bfloat16")

    assert resolve(get_settings().dtype) is torch.bfloat16

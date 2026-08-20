"""Shared resolution of the MODEL_DTYPE setting to a torch dtype.

Loading weights at half precision roughly halves a model's resident size —
Qwen2.5-1.5B is ~6.2 GB at float32 and ~3.1 GB at bfloat16, which decides
whether it fits alongside the retrieval encoders on a constrained host. "auto"
defers to the checkpoint's own declared dtype.
"""

import torch

_DTYPES = {
    "float32": torch.float32,
    "float16": torch.float16,
    "bfloat16": torch.bfloat16,
}


def resolve(name: str) -> str | torch.dtype:
    """Maps a MODEL_DTYPE value to what `from_pretrained(torch_dtype=...)` wants.

    "auto" is passed through as the literal string transformers expects.
    """
    if name == "auto":
        return "auto"
    if name not in _DTYPES:
        raise ValueError(
            f"Unknown MODEL_DTYPE: {name!r} (expected 'auto', {', '.join(sorted(_DTYPES))})"
        )
    return _DTYPES[name]

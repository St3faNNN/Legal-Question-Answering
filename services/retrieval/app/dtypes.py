"""Shared resolution of the MODEL_DTYPE setting to a torch dtype, plus optional
int8 quantization.

Loading weights at half precision roughly halves a model's resident size, which
is what makes the two encoders plus a generator fit on a memory-constrained
host. "auto" defers to the checkpoint's own declared dtype.
"""

import logging

import torch

logger = logging.getLogger("retrieval")

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


def maybe_quantize(model: torch.nn.Module, mode: str, device: str) -> torch.nn.Module:
    """Optionally apply dynamic int8 quantization to a model's Linear layers.

    On a memory-constrained CPU host this is what makes the two 568M encoders
    fit: the Linear weights (the bulk of a transformer) drop from float32 to
    int8 — about a quarter of the resident size — and x86 runs the int8 GEMM
    natively via AVX2/AVX-512 VNNI, so unlike the bfloat16 emulation trap it cuts
    memory without the latency penalty. Only Linear weights are quantized;
    activations stay float32, so the dense pooling and the BGE-M3 sparse head
    downstream are unaffected.

    ``mode`` is "none" (return the model unchanged) or "int8". The int8 kernels
    are CPU-only, so on any other device quantization is skipped with a warning
    rather than raising.
    """
    if mode == "none":
        return model
    if mode != "int8":
        raise ValueError(f"Unknown QUANTIZE: {mode!r} (expected 'none' or 'int8')")
    if device != "cpu":
        logger.warning("QUANTIZE=int8 ignored on device %s; dynamic int8 is CPU only", device)
        return model

    logger.info("Applying dynamic int8 quantization to Linear layers")
    return torch.ao.quantization.quantize_dynamic(model, {torch.nn.Linear}, dtype=torch.qint8)

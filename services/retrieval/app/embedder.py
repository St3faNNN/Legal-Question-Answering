"""BGE-M3 query encoding: dense CLS embedding plus lexical-sparse weights.

The chunk side of both representations is precomputed in vectors.sqlite, so this
only ever encodes the question. BGE-M3 is an XLM-RoBERTa encoder, which plain
`transformers` Auto classes load directly — no FlagEmbedding dependency.

The lexical arm needs BGE-M3's `sparse_linear` head, a Linear(hidden, 1) that
ships beside the weights rather than inside the encoder. It is fetched
separately and treated as optional: without it the service runs dense-only,
which is a quality reduction, not an outage.
"""

import logging
import os
import threading

import numpy as np
import torch
from transformers import AutoModel, AutoTokenizer

from .dtypes import maybe_quantize, resolve

logger = logging.getLogger("retrieval")

SPARSE_LINEAR_FILENAME = "sparse_linear.pt"


class QueryEmbedder:
    """Loaded once at startup; `load()` is where the weights are pulled."""

    def __init__(
        self,
        model_path: str,
        device: str = "auto",
        use_sparse: bool = True,
        max_length: int = 512,
        dtype: str = "float32",
        quantize: str = "none",
    ):
        self.model_path = model_path
        self.device = device if device != "auto" else ("cuda" if torch.cuda.is_available() else "cpu")
        self.dtype = dtype
        self.quantize = quantize
        self.use_sparse = use_sparse
        self.max_length = max_length
        self.tokenizer = None
        self.model = None
        self.sparse_linear = None
        # A single encoder instance is not safely concurrent, and CPU inference
        # gains nothing from overlapping requests — same rationale as the
        # Generation Service's engine lock.
        self._lock = threading.Lock()

    @property
    def loaded(self) -> bool:
        return self.model is not None

    @property
    def sparse_available(self) -> bool:
        return self.sparse_linear is not None

    def load(self) -> None:
        logger.info("Loading embedding model %s onto %s (%s)", self.model_path, self.device, self.dtype)
        self.tokenizer = AutoTokenizer.from_pretrained(self.model_path)
        self.model = (
            AutoModel.from_pretrained(
                self.model_path,
                torch_dtype=resolve(self.dtype),
                # Without this, transformers materializes the full state dict in
                # RAM and then copies it into a freshly initialized model —
                # roughly double the peak. Loading straight into the model keeps
                # the peak at about the weight size.
                low_cpu_mem_usage=True,
            )
            .to(self.device)
            .eval()
        )
        # int8 the encoder body (not the tiny sparse head, kept float32 below).
        self.model = maybe_quantize(self.model, self.quantize, self.device)
        if self.use_sparse:
            self.sparse_linear = self._load_sparse_linear()
        logger.info("Embedding model ready (sparse arm %s)", "on" if self.sparse_available else "off")

    def _load_sparse_linear(self) -> torch.nn.Module | None:
        """The lexical arm is a bonus, not a requirement — every failure mode
        here (no network, file absent, unexpected state dict) degrades to
        dense-only rather than taking the service down at startup."""
        try:
            path = os.path.join(self.model_path, SPARSE_LINEAR_FILENAME)
            if not os.path.exists(path):
                from huggingface_hub import hf_hub_download

                path = hf_hub_download(repo_id=self.model_path, filename=SPARSE_LINEAR_FILENAME)

            state = torch.load(path, map_location="cpu")
            linear = torch.nn.Linear(self.model.config.hidden_size, 1)
            linear.load_state_dict(state)
            # Must match the encoder's dtype: the head consumes its hidden states.
            return linear.to(device=self.device, dtype=self.model.dtype).eval()
        except Exception as error:  # noqa: BLE001 - see docstring
            logger.warning(
                "BGE-M3 sparse head unavailable (%s); retrieval will run dense-only", error
            )
            return None

    def encode(self, text: str) -> tuple[np.ndarray, dict[int, float]]:
        """Returns the L2-normalized dense vector and the lexical weights.

        The weights are empty when the sparse head is unavailable, which
        `VectorStore.search_sparse` treats as "no lexical arm".
        """
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Embedding model is not loaded")

        with self._lock:
            inputs = self.tokenizer(
                text,
                return_tensors="pt",
                max_length=self.max_length,
                truncation=True,
            ).to(self.device)
            with torch.no_grad():
                hidden = self.model(**inputs).last_hidden_state
                # BGE-M3 pools dense from CLS, not from the token mean.
                dense = torch.nn.functional.normalize(hidden[:, 0], p=2, dim=-1)
                weights = (
                    self._token_weights(hidden, inputs["input_ids"])
                    if self.sparse_linear is not None
                    else {}
                )

        # .float() first: numpy has no bfloat16, so a half-precision tensor
        # cannot be converted directly.
        return dense[0].float().cpu().numpy().astype(np.float32), weights

    def _token_weights(self, hidden: torch.Tensor, input_ids: torch.Tensor) -> dict[int, float]:
        """BGE-M3 lexical weights: relu(sparse_linear(h)) per token, then the max
        weight per distinct token id. Special tokens carry no lexical meaning and
        would otherwise match every chunk."""
        scores = torch.relu(self.sparse_linear(hidden)).squeeze(-1)[0]
        special = set(self.tokenizer.all_special_ids)

        weights: dict[int, float] = {}
        for token_id, score in zip(input_ids[0].tolist(), scores.tolist()):
            if token_id in special:
                continue
            if score > weights.get(token_id, 0.0):
                weights[token_id] = float(score)
        return weights

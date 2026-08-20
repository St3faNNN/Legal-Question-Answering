"""Cross-encoder reranking with BAAI/bge-reranker-v2-m3.

The fused candidate pool is ordered by rank arithmetic, which knows nothing
about the question beyond what the two retrievers already scored. The reranker
reads each (question, passage) pair jointly and produces the ordering that
actually decides what reaches the generator.
"""

import logging
import threading

import numpy as np
import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

from .dtypes import maybe_quantize, resolve

logger = logging.getLogger("retrieval")


class CrossEncoderReranker:
    def __init__(
        self,
        model_path: str,
        device: str = "auto",
        max_length: int = 256,
        batch_size: int = 16,
        dtype: str = "float32",
        quantize: str = "none",
    ):
        self.model_path = model_path
        self.device = device if device != "auto" else ("cuda" if torch.cuda.is_available() else "cpu")
        self.dtype = dtype
        self.quantize = quantize
        self.max_length = max_length
        self.batch_size = batch_size
        self.tokenizer = None
        self.model = None
        self._lock = threading.Lock()

    @property
    def loaded(self) -> bool:
        return self.model is not None

    def load(self) -> None:
        logger.info("Loading reranker %s onto %s (%s)", self.model_path, self.device, self.dtype)
        self.tokenizer = AutoTokenizer.from_pretrained(self.model_path)
        self.model = (
            AutoModelForSequenceClassification.from_pretrained(
                self.model_path,
                torch_dtype=resolve(self.dtype),
                # Halves peak load memory — see QueryEmbedder.load.
                low_cpu_mem_usage=True,
            )
            .to(self.device)
            .eval()
        )
        self.model = maybe_quantize(self.model, self.quantize, self.device)
        logger.info("Reranker ready")

    def score(self, question: str, passages: list[str]) -> list[float]:
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Reranker is not loaded")
        if not passages:
            return []

        scores: list[float] = []
        with self._lock:
            for start in range(0, len(passages), self.batch_size):
                batch = passages[start : start + self.batch_size]
                inputs = self.tokenizer(
                    [question] * len(batch),
                    batch,
                    padding=True,
                    truncation=True,
                    max_length=self.max_length,
                    return_tensors="pt",
                ).to(self.device)
                with torch.no_grad():
                    logits = self.model(**inputs).logits
                scores.extend(logits.view(-1).float().cpu().tolist())
        return scores


class OnnxCrossEncoderReranker:
    """ONNX Runtime backend for the cross-encoder, interface-compatible with
    :class:`CrossEncoderReranker` (same ``load`` / ``score`` / ``loaded``).

    Runs a prebuilt int8 ONNX export of bge-reranker-v2-m3 (default:
    ``onnx-community/bge-reranker-v2-m3-ONNX``) through onnxruntime, which is
    markedly faster than torch dynamic-int8 on an AVX2 CPU (no VNNI for torch to
    exploit). The embedder is untouched.

    The tokenizer is the *same* AutoTokenizer the torch path uses, so tokens are
    byte-identical across backends — any ranking difference is model numerics,
    not tokenization. onnxruntime is imported lazily in ``load`` so the default
    torch path never requires it, and every failure mode (missing runtime,
    unfetchable or unopenable model) raises at load time rather than degrading
    silently.
    """

    def __init__(
        self,
        model_path: str,
        onnx_repo: str,
        onnx_file: str,
        max_length: int = 256,
        batch_size: int = 16,
    ):
        self.model_path = model_path
        self.onnx_repo = onnx_repo
        self.onnx_file = onnx_file
        self.max_length = max_length
        self.batch_size = batch_size
        self.tokenizer = None
        self.session = None
        self._input_names: list[str] = []
        self._output_name: str | None = None
        self._lock = threading.Lock()

    @property
    def loaded(self) -> bool:
        return self.session is not None

    def load(self) -> None:
        try:
            import onnxruntime as ort
        except ImportError as error:
            raise RuntimeError(
                "RERANK_BACKEND=onnx requires the 'onnxruntime' package, which is not "
                "installed. Install onnxruntime, or set RERANK_BACKEND=torch, or "
                "RERANK_BACKEND=none to disable reranking."
            ) from error

        logger.info("Loading reranker (onnx) %s/%s", self.onnx_repo, self.onnx_file)
        try:
            from huggingface_hub import hf_hub_download

            model_file = hf_hub_download(repo_id=self.onnx_repo, filename=self.onnx_file)
        except Exception as error:  # noqa: BLE001 - fail loud, carry the cause
            raise RuntimeError(
                f"Could not fetch ONNX reranker {self.onnx_repo}/{self.onnx_file}: {error}"
            ) from error

        try:
            self.session = ort.InferenceSession(model_file, providers=["CPUExecutionProvider"])
        except Exception as error:  # noqa: BLE001
            raise RuntimeError(f"Could not open ONNX reranker at {model_file}: {error}") from error

        self.tokenizer = AutoTokenizer.from_pretrained(self.model_path)
        self._input_names = [inp.name for inp in self.session.get_inputs()]
        self._output_name = self.session.get_outputs()[0].name
        logger.info(
            "Reranker (onnx) ready; inputs=%s output=%s", self._input_names, self._output_name
        )

    def score(self, question: str, passages: list[str]) -> list[float]:
        if self.session is None or self.tokenizer is None:
            raise RuntimeError("Reranker is not loaded")
        if not passages:
            return []

        scores: list[float] = []
        with self._lock:
            for start in range(0, len(passages), self.batch_size):
                batch = passages[start : start + self.batch_size]
                encoded = self.tokenizer(
                    [question] * len(batch),
                    batch,
                    padding=True,
                    truncation=True,
                    max_length=self.max_length,
                    return_tensors="np",
                )
                logits = self.session.run([self._output_name], self._feed(encoded))[0]
                scores.extend(np.asarray(logits).reshape(-1).astype(float).tolist())
        return scores

    def _feed(self, encoded) -> dict:
        """Feed only the inputs the graph declares, as int64. XLM-R has no
        token_type_ids; if an export declares one, supply zeros."""
        feed: dict = {}
        for name in self._input_names:
            if name in encoded:
                feed[name] = encoded[name].astype(np.int64)
            elif name == "token_type_ids":
                feed[name] = np.zeros_like(encoded["input_ids"], dtype=np.int64)
        return feed

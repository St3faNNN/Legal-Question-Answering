"""llama.cpp-backed generation, for CPU hosts.

Why this exists alongside `GenerationEngine` (transformers): CPU inference here
is memory-bandwidth-bound, not compute-bound. Every generated token re-reads the
whole weight tensor, so float32 weights cost four bytes per parameter per token.
Measured on this stack, Qwen2.5-0.5B at float32 spent ~110s on prefill of a
~3600-token context plus ~0.55s per generated token — roughly four minutes for
one grounded answer, from a model too small to synthesize legal Macedonian.

A Q4_K_M quantization reads ~4x less memory per token and llama.cpp's kernels
are hand-tuned for AVX2/AVX-512, so a *larger* model runs *faster* here than the
small one did under transformers. That resolves what looked like a quality/speed
trade-off: 3B quantized beats 0.5B float32 on both.

Selected with GENERATION_BACKEND=llama. The transformers engine remains the
default so seq2seq checkpoints and GPU hosts are unaffected.
"""

import logging
import threading
from typing import Iterator

from .model import GenerationResult

logger = logging.getLogger("generation")


class LlamaEngine:
    """Interface-compatible with :class:`~app.model.GenerationEngine`.

    Only the chat path is implemented against llama.cpp's chat completion API,
    which renders messages with the template embedded in the GGUF itself — there
    is no separate tokenizer repo to keep in sync. The plain-string styles
    ("clue", "qa", "unifiedqa") fall through to raw completion.
    """

    def __init__(
        self,
        repo_id: str,
        filename: str,
        max_input_tokens: int = 8192,
        max_output_tokens: int = 512,
        n_threads: int | None = None,
        n_batch: int = 512,
    ):
        self.repo_id = repo_id
        self.filename = filename
        self.max_input_tokens = max_input_tokens
        self.max_output_tokens = max_output_tokens
        self.n_threads = n_threads
        self.n_batch = n_batch
        self._llama = None
        # llama.cpp holds one mutable context; concurrent generations would
        # interleave their KV caches.
        self._lock = threading.Lock()

    @property
    def loaded(self) -> bool:
        return self._llama is not None

    @property
    def is_causal(self) -> bool:
        """GGUF chat checkpoints are decoder-only by construction."""
        return True

    def load(self) -> None:
        # Imported here so the module can be imported (and unit-tested) on a host
        # without the native library built.
        from llama_cpp import Llama

        logger.info(
            "Loading GGUF %s (%s) with n_ctx=%d, n_threads=%s",
            self.repo_id,
            self.filename,
            self.max_input_tokens,
            self.n_threads or "auto",
        )
        self._llama = Llama.from_pretrained(
            repo_id=self.repo_id,
            # A glob, so a repo that renames its quantizations (q4_k_m vs
            # Q4_K_M) still resolves without a config change.
            filename=self.filename,
            n_ctx=self.max_input_tokens,
            n_threads=self.n_threads,
            n_batch=self.n_batch,
            verbose=False,
        )
        logger.info("GGUF model loaded")

    def _require_loaded(self):
        if self._llama is None:
            raise RuntimeError("Model is not loaded")
        return self._llama

    def _completion_kwargs(self) -> dict:
        # temperature=0 is greedy: a grounded legal answer should be reproducible
        # for the same context, and sampling only invents detail here.
        #
        # repeat_penalty=1.0 disables llama.cpp's default 1.1. That default is
        # tuned for open-ended chat, where looping is the failure mode; here it
        # is actively harmful for the same reason no_repeat_ngram_size is off for
        # causal models (see GenerationEngine.no_repeat_ngram_size). Macedonian
        # legislation repeats fixed phrases — "работни дена", "од овој закон",
        # article numbers — and penalizing those tokens pushed the model off
        # Macedonian entirely, emitting Russian forms like "помимо". It also made
        # supposedly greedy decoding vary between runs of the same question.
        return {
            "max_tokens": self.max_output_tokens,
            "temperature": 0.0,
            "repeat_penalty": 1.0,
        }

    def generate_chat(self, messages: list[dict]) -> GenerationResult:
        llama = self._require_loaded()
        with self._lock:
            response = llama.create_chat_completion(messages=messages, **self._completion_kwargs())

        choice = response["choices"][0]["message"].get("content") or ""
        tokens = int(response.get("usage", {}).get("completion_tokens", 0))
        return GenerationResult(text=choice.strip(), tokens=tokens)

    def generate_chat_stream(self, messages: list[dict]) -> Iterator[str]:
        llama = self._require_loaded()
        with self._lock:
            for part in llama.create_chat_completion(
                messages=messages, stream=True, **self._completion_kwargs()
            ):
                text = part["choices"][0].get("delta", {}).get("content")
                if text:
                    yield text

    def generate(self, prompt: str) -> GenerationResult:
        llama = self._require_loaded()
        with self._lock:
            response = llama.create_completion(prompt=prompt, **self._completion_kwargs())

        choice = response["choices"][0].get("text") or ""
        tokens = int(response.get("usage", {}).get("completion_tokens", 0))
        return GenerationResult(text=choice.strip(), tokens=tokens)

    def generate_stream(self, prompt: str) -> Iterator[str]:
        llama = self._require_loaded()
        with self._lock:
            for part in llama.create_completion(prompt=prompt, stream=True, **self._completion_kwargs()):
                text = part["choices"][0].get("text")
                if text:
                    yield text

    def token_count(self, text: str) -> int:
        if self._llama is None or not text:
            return 0
        return len(self._llama.tokenize(text.encode("utf-8"), add_bos=False))

"""Checkpoint loading and text generation for both model families.

The engine is architecture-agnostic. It serves either:

- a **causal** chat model (the production default, Qwen2.5-1.5B-Instruct), whose
  prompt is rendered with the model's own chat template and whose answer is the
  continuation after the prompt; or
- a **seq2seq** checkpoint (the fine-tuned BART from `bart_eval_with_coref.ipynb`,
  or a stand-in like google/flan-t5-small), whose answer is the whole decode.

Which one is in use is detected from the checkpoint config rather than
configured, so pointing MODEL_PATH at a different model is the only change
needed. The seq2seq generate parameters remain the notebook's verbatim:
tokenize with `max_length=512, truncation=True`; generate with `max_length=64,
num_beams=4, early_stopping=True` under `torch.no_grad()`; decode with
`skip_special_tokens=True`.
"""

import logging
import threading
from dataclasses import dataclass

import torch
from transformers import (
    AutoConfig,
    AutoModelForCausalLM,
    AutoModelForSeq2SeqLM,
    AutoTokenizer,
    TextIteratorStreamer,
)

from .dtypes import resolve

logger = logging.getLogger("generation")


@dataclass(frozen=True)
class GenerationResult:
    text: str
    tokens: int


class GenerationEngine:
    def __init__(
        self,
        model_path: str,
        device: str = "auto",
        max_input_tokens: int = 512,
        max_output_tokens: int = 64,
        num_beams: int = 4,
        dtype: str = "auto",
        no_repeat_ngram_size: int | None = None,
    ):
        self.model_path = model_path
        self.max_input_tokens = max_input_tokens
        self.max_output_tokens = max_output_tokens
        self.num_beams = num_beams
        self.dtype = dtype
        self._configured_no_repeat = no_repeat_ngram_size
        self.device = device if device != "auto" else ("cuda" if torch.cuda.is_available() else "cpu")
        self.tokenizer = None
        self.model = None
        self.is_causal = False
        # Beam search on a single model instance is not safely concurrent, and
        # CPU inference gains nothing from overlapping requests.
        self._lock = threading.Lock()

    @property
    def loaded(self) -> bool:
        return self.model is not None

    @property
    def no_repeat_ngram_size(self) -> int:
        """N-gram repetition blocking, 0 to disable.

        It exists to suppress the stuttering a small seq2seq model produces
        ("Hamlet and King Lear ... Hamlet and King Lear"). On a grounded legal
        answer it actively hurts: Macedonian legislation repeats fixed phrases
        ("од овој закон", "став (1)") and citations legitimately, and blocking
        3-grams stops the model quoting the same article twice. So it defaults
        on for seq2seq and off for causal chat models.
        """
        if self._configured_no_repeat is not None:
            return self._configured_no_repeat
        return 0 if self.is_causal else 3

    def load(self) -> None:
        config = AutoConfig.from_pretrained(self.model_path)
        self.is_causal = not getattr(config, "is_encoder_decoder", False)
        logger.info(
            "Loading %s checkpoint from %s onto %s (%s)",
            "causal" if self.is_causal else "seq2seq",
            self.model_path,
            self.device,
            self.dtype,
        )

        try:
            self.tokenizer = AutoTokenizer.from_pretrained(self.model_path)
        except Exception:
            # Some checkpoints ship only a sentencepiece model without a
            # prebuilt tokenizer.json; fall back to the slow tokenizer.
            self.tokenizer = AutoTokenizer.from_pretrained(self.model_path, use_fast=False)

        loader = AutoModelForCausalLM if self.is_causal else AutoModelForSeq2SeqLM
        model, loading_info = loader.from_pretrained(
            self.model_path,
            output_loading_info=True,
            torch_dtype=resolve(self.dtype),
            # Without this, transformers materializes the full state dict in RAM
            # and then copies it into a freshly initialized model — roughly
            # double the peak, which is what OOMs a memory-constrained host.
            low_cpu_mem_usage=True,
        )
        # A checkpoint with missing keys would be silently completed with
        # randomly initialized weights and generate garbage — refuse to serve.
        if loading_info["missing_keys"]:
            raise RuntimeError(
                f"Checkpoint at {self.model_path} is incomplete: "
                f"{len(loading_info['missing_keys'])} missing keys "
                f"(e.g. {loading_info['missing_keys'][:3]})"
            )
        self.model = model.to(self.device).eval()
        logger.info("Model loaded on %s", self.device)

    def apply_chat_template(self, messages: list[dict]) -> str:
        """Renders a message list with the checkpoint's own chat template.

        Falls back to a plain role-prefixed transcript for checkpoints that
        declare no template, so a misconfigured PROMPT_STYLE degrades to a
        readable prompt instead of raising.
        """
        if self.tokenizer is None:
            raise RuntimeError("Model is not loaded")

        if getattr(self.tokenizer, "chat_template", None):
            return self.tokenizer.apply_chat_template(
                messages, tokenize=False, add_generation_prompt=True
            )

        logger.warning("Checkpoint %s declares no chat template; using a plain transcript", self.model_path)
        return "\n\n".join(f"{message['role']}: {message['content']}" for message in messages) + "\n\nassistant:"

    def _encode(self, prompt: str):
        return self.tokenizer(
            prompt,
            return_tensors="pt",
            max_length=self.max_input_tokens,
            truncation=True,
        ).to(self.device)

    def _generation_kwargs(self, streaming: bool) -> dict:
        """Causal models continue the prompt, so their budget is `max_new_tokens`;
        seq2seq models decode from scratch and keep the notebook's `max_length`.

        Streaming forces greedy decoding in both families — HF's
        TextIteratorStreamer cannot stream beam search.
        """
        beams = 1 if streaming else self.num_beams
        kwargs: dict = {"num_beams": beams}
        if self.no_repeat_ngram_size > 0:
            kwargs["no_repeat_ngram_size"] = self.no_repeat_ngram_size
        if beams == 1:
            kwargs["do_sample"] = False
        else:
            kwargs["early_stopping"] = True

        if self.is_causal:
            kwargs["max_new_tokens"] = self.max_output_tokens
            kwargs["pad_token_id"] = self.tokenizer.pad_token_id or self.tokenizer.eos_token_id
        else:
            kwargs["max_length"] = self.max_output_tokens
        return kwargs

    def generate(self, prompt: str) -> GenerationResult:
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Model is not loaded")
        with self._lock:
            inputs = self._encode(prompt)
            with torch.no_grad():
                output = self.model.generate(**inputs, **self._generation_kwargs(streaming=False))

        sequence = output[0]
        if self.is_causal:
            # Everything before this is the prompt echoed back.
            generated = sequence[inputs["input_ids"].shape[-1] :]
        else:
            generated = sequence

        text = self.tokenizer.decode(generated, skip_special_tokens=True)
        return GenerationResult(text=text.strip(), tokens=int(generated.shape[-1]))

    def generate_stream(self, prompt: str):
        """Yield the answer text incrementally as it is produced.

        Token streaming (via HF TextIteratorStreamer) requires greedy decoding —
        beam search cannot stream. generate() runs in a background thread while
        the caller consumes the streamer; the lock serializes generations,
        matching generate()'s single-instance model.
        """
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Model is not loaded")

        inputs = self._encode(prompt)
        streamer = TextIteratorStreamer(
            self.tokenizer, skip_prompt=self.is_causal, skip_special_tokens=True
        )
        kwargs = self._generation_kwargs(streaming=True)

        def run() -> None:
            with torch.no_grad():
                self.model.generate(**inputs, **kwargs, streamer=streamer)

        with self._lock:
            thread = threading.Thread(target=run)
            thread.start()
            for text in streamer:
                if text:
                    yield text
            thread.join()

    def generate_chat(self, messages: list[dict]) -> GenerationResult:
        """Chat-style generation from a message list.

        Present so this engine and :class:`~app.llama_engine.LlamaEngine` expose
        the same surface: llama.cpp renders the template inside its own chat
        completion call, whereas here the template is applied first and the
        result generated as an ordinary prompt.
        """
        return self.generate(self.apply_chat_template(messages))

    def generate_chat_stream(self, messages: list[dict]):
        yield from self.generate_stream(self.apply_chat_template(messages))

    def token_count(self, text: str) -> int:
        if self.tokenizer is None:
            return 0
        return len(self.tokenizer(text, add_special_tokens=False).input_ids)

"""Seq2seq checkpoint loading and text generation.

The engine is model-agnostic (Auto classes): it serves the fine-tuned BART
checkpoint from the reference notebook when available, or a stand-in seq2seq
model such as google/flan-t5-small. Tokenizer/generate parameters are verbatim
from `bart_eval_with_coref.ipynb`: tokenize with `max_length=512,
truncation=True`; generate with `max_length=64, num_beams=4,
early_stopping=True` under `torch.no_grad()`; decode with
`skip_special_tokens=True`.
"""

import logging
import threading
from dataclasses import dataclass

import torch
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer, TextIteratorStreamer

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
    ):
        self.model_path = model_path
        self.max_input_tokens = max_input_tokens
        self.max_output_tokens = max_output_tokens
        self.num_beams = num_beams
        self.device = device if device != "auto" else ("cuda" if torch.cuda.is_available() else "cpu")
        self.tokenizer = None
        self.model = None
        # Beam search on a single model instance is not safely concurrent, and
        # CPU inference gains nothing from overlapping requests.
        self._lock = threading.Lock()

    @property
    def loaded(self) -> bool:
        return self.model is not None

    def load(self) -> None:
        logger.info("Loading seq2seq checkpoint from %s onto %s", self.model_path, self.device)
        try:
            self.tokenizer = AutoTokenizer.from_pretrained(self.model_path)
        except Exception:
            # Some checkpoints ship only a sentencepiece model without a
            # prebuilt tokenizer.json; fall back to the slow tokenizer.
            self.tokenizer = AutoTokenizer.from_pretrained(self.model_path, use_fast=False)
        model, loading_info = AutoModelForSeq2SeqLM.from_pretrained(
            self.model_path, output_loading_info=True
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

    def generate(self, prompt: str) -> GenerationResult:
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Model is not loaded")
        with self._lock:
            inputs = self.tokenizer(
                prompt,
                return_tensors="pt",
                max_length=self.max_input_tokens,
                truncation=True,
            ).to(self.device)
            with torch.no_grad():
                output = self.model.generate(
                    **inputs,
                    max_length=self.max_output_tokens,
                    num_beams=self.num_beams,
                    early_stopping=True,
                    # Suppress the repeated-phrase artifacts the small stand-in
                    # model produces (e.g. "Hamlet and King Lear ... Hamlet and
                    # King Lear").
                    no_repeat_ngram_size=3,
                )
        text = self.tokenizer.decode(output[0], skip_special_tokens=True)
        return GenerationResult(text=text.strip(), tokens=int(output.shape[-1]))

    def generate_stream(self, prompt: str):
        """Yield the answer text incrementally as it is produced.

        Token streaming (via HF TextIteratorStreamer) requires greedy decoding —
        beam search cannot stream — so this path uses num_beams=1. generate()
        runs in a background thread while the caller consumes the streamer; the
        lock serializes generations, matching generate()'s single-instance model.
        """
        if self.model is None or self.tokenizer is None:
            raise RuntimeError("Model is not loaded")

        inputs = self.tokenizer(
            prompt,
            return_tensors="pt",
            max_length=self.max_input_tokens,
            truncation=True,
        ).to(self.device)
        streamer = TextIteratorStreamer(self.tokenizer, skip_special_tokens=True)

        def run() -> None:
            with torch.no_grad():
                self.model.generate(
                    **inputs,
                    max_length=self.max_output_tokens,
                    num_beams=1,
                    do_sample=False,
                    no_repeat_ngram_size=3,
                    streamer=streamer,
                )

        with self._lock:
            thread = threading.Thread(target=run)
            thread.start()
            for text in streamer:
                if text:
                    yield text
            thread.join()

    def token_count(self, text: str) -> int:
        if self.tokenizer is None:
            return 0
        return len(self.tokenizer(text, add_special_tokens=False).input_ids)

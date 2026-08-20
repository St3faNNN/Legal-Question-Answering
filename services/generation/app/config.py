"""Environment-driven configuration for the Generation Service."""

import os
from dataclasses import dataclass

DEFAULT_MODEL_NAME = "qwen2.5-1.5b-instruct"


def get_model_name() -> str:
    """Name reported in Generate responses. Override MODEL_NAME when serving
    a different checkpoint so the served model is never misreported."""
    return os.environ.get("MODEL_NAME", DEFAULT_MODEL_NAME)


@dataclass(frozen=True)
class Settings:
    model_path: str
    device: str  # "auto" | "cpu" | "cuda"
    dtype: str  # "auto" | "float32" | "bfloat16" | "float16"
    prompt_style: str  # "chat" | "clue" | "qa" | "unifiedqa"
    max_input_tokens: int
    max_output_tokens: int
    num_beams: int
    #: None defers to the engine's per-family default (see GenerationEngine).
    no_repeat_ngram_size: int | None
    backend: str  # "transformers" | "llama"
    #: llama backend only: the GGUF repo and a filename glob within it.
    gguf_repo: str
    gguf_file: str
    #: llama backend only. None lets llama.cpp pick (physical cores).
    n_threads: int | None


def get_settings() -> Settings:
    return Settings(
        model_path=os.environ.get("MODEL_PATH", "Qwen/Qwen2.5-1.5B-Instruct"),
        device=os.environ.get("DEVICE", "auto"),
        # float32, deliberately — NOT "auto". Qwen2.5's config declares
        # bfloat16, and PyTorch has no fast CPU kernel for bfloat16 without AMX,
        # so "auto" silently lands on an emulated path: measured at over 400s
        # for one short-context answer, against seconds at float32. The halved
        # memory is worthless if the service is unusable. Use "auto" on a GPU.
        dtype=os.environ.get("MODEL_DTYPE", "float32"),
        prompt_style=os.environ.get("PROMPT_STYLE", "chat"),
        # The corpus is legal prose: ten reranked articles run to several
        # thousand tokens, which Qwen2.5's 32k window accommodates. A seq2seq
        # stand-in must lower this back to its own limit.
        max_input_tokens=int(os.environ.get("MAX_INPUT_TOKENS", "8192")),
        # CPU decoding costs ~0.55s per token, so this is a latency budget, not
        # just a length limit. The prompt asks for at most three sentences, but
        # a 0.5B model does not reliably obey that on statutory text full of
        # enumerated lists: measured runs went 32 tokens on one question and
        # straight to the cap on the next. This bounds the worst case at roughly
        # 105s of decoding on top of prefill, keeping the whole job inside
        # StreamChatReply::TIMEOUT_SECONDS with headroom.
        max_output_tokens=int(os.environ.get("MAX_OUTPUT_TOKENS", "192")),
        # Beam search on a 1.5B causal model is prohibitively slow on CPU and
        # buys little for grounded answers; greedy is the default here.
        num_beams=int(os.environ.get("NUM_BEAMS", "1")),
        no_repeat_ngram_size=(
            int(os.environ["NO_REPEAT_NGRAM_SIZE"]) if "NO_REPEAT_NGRAM_SIZE" in os.environ else None
        ),
        # "transformers" stays the default so seq2seq checkpoints and GPU hosts
        # are unaffected; "llama" is what makes CPU inference usable here.
        backend=os.environ.get("BACKEND", "transformers"),
        gguf_repo=os.environ.get("GGUF_REPO", "Qwen/Qwen2.5-3B-Instruct-GGUF"),
        # A glob: quantization files are named inconsistently across repos
        # (q4_k_m vs Q4_K_M), and llama.cpp resolves the pattern against the hub.
        gguf_file=os.environ.get("GGUF_FILE", "*q4_k_m*.gguf"),
        n_threads=(int(os.environ["N_THREADS"]) if "N_THREADS" in os.environ else None),
    )

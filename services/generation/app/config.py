"""Environment-driven configuration for the Generation Service."""

import os
from dataclasses import dataclass

DEFAULT_MODEL_NAME = "bart-jeopardy-final"


def get_model_name() -> str:
    """Name reported in /generate responses. Override MODEL_NAME when serving
    a different checkpoint (e.g. facebook/bart-large as a stand-in) so the
    served model is never misreported."""
    return os.environ.get("MODEL_NAME", DEFAULT_MODEL_NAME)


@dataclass(frozen=True)
class Settings:
    model_path: str
    device: str  # "auto" | "cpu" | "cuda"
    prompt_style: str  # "clue" | "qa"
    max_input_tokens: int
    max_output_tokens: int
    num_beams: int


def get_settings() -> Settings:
    return Settings(
        model_path=os.environ.get("MODEL_PATH", "/models/bart_jeopardy_final"),
        device=os.environ.get("DEVICE", "auto"),
        prompt_style=os.environ.get("PROMPT_STYLE", "clue"),
        max_input_tokens=int(os.environ.get("MAX_INPUT_TOKENS", "512")),
        max_output_tokens=int(os.environ.get("MAX_OUTPUT_TOKENS", "64")),
        num_beams=int(os.environ.get("NUM_BEAMS", "4")),
    )

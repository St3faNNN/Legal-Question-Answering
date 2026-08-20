"""Generation-parameter selection, without loading a checkpoint.

GenerationEngine is constructed but never `load()`ed; `is_causal` is set
directly to stand in for what the checkpoint config would have said.
"""

import pytest

from app.config import get_settings
from app.model import GenerationEngine


class FakeTokenizer:
    pad_token_id = 151643
    eos_token_id = 151645


def engine(is_causal: bool, **kwargs) -> GenerationEngine:
    built = GenerationEngine("some/checkpoint", device="cpu", **kwargs)
    built.is_causal = is_causal
    # load() would set this; the causal path reads it for pad_token_id.
    built.tokenizer = FakeTokenizer()
    return built


def test_causal_models_budget_new_tokens_not_total_length():
    """A causal model continues the prompt, so max_length would be consumed by
    the prompt itself and leave nothing for the answer."""
    kwargs = engine(True, max_output_tokens=512)._generation_kwargs(streaming=False)

    assert kwargs["max_new_tokens"] == 512
    assert "max_length" not in kwargs


def test_seq2seq_models_keep_the_notebook_max_length():
    kwargs = engine(False, max_output_tokens=64)._generation_kwargs(streaming=False)

    assert kwargs["max_length"] == 64
    assert "max_new_tokens" not in kwargs


def test_streaming_forces_greedy_because_beams_cannot_stream():
    kwargs = engine(False, num_beams=4)._generation_kwargs(streaming=True)

    assert kwargs["num_beams"] == 1
    assert kwargs["do_sample"] is False
    assert "early_stopping" not in kwargs


def test_beam_search_is_kept_for_unary_seq2seq_generation():
    kwargs = engine(False, num_beams=4)._generation_kwargs(streaming=False)

    assert kwargs["num_beams"] == 4
    assert kwargs["early_stopping"] is True


def test_ngram_blocking_is_off_for_causal_chat_models():
    """Legal prose repeats fixed phrases and citations legitimately; blocking
    3-grams would stop the model quoting the same article twice."""
    assert "no_repeat_ngram_size" not in engine(True)._generation_kwargs(streaming=False)


def test_ngram_blocking_stays_on_for_seq2seq_stand_ins():
    kwargs = engine(False)._generation_kwargs(streaming=False)

    assert kwargs["no_repeat_ngram_size"] == 3


def test_explicit_ngram_setting_overrides_the_family_default():
    assert engine(True, no_repeat_ngram_size=4)._generation_kwargs(streaming=False)[
        "no_repeat_ngram_size"
    ] == 4
    assert "no_repeat_ngram_size" not in engine(False, no_repeat_ngram_size=0)._generation_kwargs(
        streaming=False
    )


def test_setting_defaults_to_deferring_to_the_engine(monkeypatch):
    monkeypatch.delenv("NO_REPEAT_NGRAM_SIZE", raising=False)

    assert get_settings().no_repeat_ngram_size is None


def test_setting_is_env_driven(monkeypatch):
    monkeypatch.setenv("NO_REPEAT_NGRAM_SIZE", "5")

    assert get_settings().no_repeat_ngram_size == 5


@pytest.mark.parametrize("streaming", [True, False])
def test_causal_generation_always_sets_a_pad_token(streaming):
    """Generation warns and can misbehave without one."""
    assert engine(True)._generation_kwargs(streaming=streaming)["pad_token_id"] == 151643


def test_pad_token_falls_back_to_eos():
    """Some causal checkpoints declare no pad token at all."""
    built = engine(True)
    built.tokenizer.pad_token_id = None

    assert built._generation_kwargs(streaming=False)["pad_token_id"] == 151645

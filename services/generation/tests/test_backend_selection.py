import pytest

from app.config import get_settings
from app.llama_engine import LlamaEngine
from app.main import build_engine
from app.model import GenerationEngine


def test_llama_is_the_default_backend_for_this_stack(monkeypatch):
    """CPU generation is memory-bandwidth-bound: the float32 transformers path
    spent ~4 minutes on one answer from a model too small to synthesize."""
    monkeypatch.setenv("BACKEND", "llama")

    assert isinstance(build_engine(get_settings()), LlamaEngine)


def test_transformers_backend_still_selectable(monkeypatch):
    monkeypatch.setenv("BACKEND", "transformers")

    assert isinstance(build_engine(get_settings()), GenerationEngine)


def test_backend_defaults_to_transformers_so_seq2seq_hosts_are_unaffected(monkeypatch):
    monkeypatch.delenv("BACKEND", raising=False)

    assert get_settings().backend == "transformers"


def test_unknown_backend_raises_rather_than_silently_defaulting(monkeypatch):
    monkeypatch.setenv("BACKEND", "ollama")

    with pytest.raises(ValueError, match="Unknown BACKEND"):
        build_engine(get_settings())


def test_gguf_filename_is_a_glob_so_repo_naming_differences_resolve(monkeypatch):
    monkeypatch.delenv("GGUF_FILE", raising=False)

    assert "*" in get_settings().gguf_file


def test_llama_engine_reports_unloaded_before_load():
    engine = LlamaEngine(repo_id="r", filename="*.gguf")

    assert engine.loaded is False
    assert engine.is_causal is True


def test_llama_engine_refuses_to_generate_before_load():
    engine = LlamaEngine(repo_id="r", filename="*.gguf")

    with pytest.raises(RuntimeError, match="not loaded"):
        engine.generate_chat([{"role": "user", "content": "hi"}])


def test_llama_engine_token_count_is_zero_before_load():
    assert LlamaEngine(repo_id="r", filename="*.gguf").token_count("текст") == 0

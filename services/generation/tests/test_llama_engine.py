"""Behaviour of the llama.cpp engine against a fake llama_cpp.Llama.

The native library is never loaded here: these cover the translation between
llama.cpp's completion API and the engine interface the servicer depends on.
"""

import pytest

from app.llama_engine import LlamaEngine


class FakeLlama:
    def __init__(self, content="Одговор.", completion_tokens=3):
        self.content = content
        self.completion_tokens = completion_tokens
        self.chat_calls = []
        self.completion_calls = []

    def create_chat_completion(self, messages, stream=False, **kwargs):
        self.chat_calls.append({"messages": messages, "stream": stream, **kwargs})
        if not stream:
            return {
                "choices": [{"message": {"content": self.content}}],
                "usage": {"completion_tokens": self.completion_tokens},
            }
        return iter(
            [{"choices": [{"delta": {"content": part}}]} for part in self.content.split(" ")]
            + [{"choices": [{"delta": {}}]}]  # a final chunk carrying no content
        )

    def create_completion(self, prompt, stream=False, **kwargs):
        self.completion_calls.append({"prompt": prompt, "stream": stream, **kwargs})
        if not stream:
            return {
                "choices": [{"text": self.content}],
                "usage": {"completion_tokens": self.completion_tokens},
            }
        return iter([{"choices": [{"text": self.content}]}])

    def tokenize(self, raw, add_bos=False):
        return list(raw)


def engine(fake=None, **kwargs):
    instance = LlamaEngine(repo_id="r", filename="*.gguf", **kwargs)
    instance._llama = fake or FakeLlama()
    return instance


def test_chat_generation_returns_text_and_token_count():
    instance = engine(FakeLlama(content="  Одговор.  ", completion_tokens=7))

    result = instance.generate_chat([{"role": "user", "content": "Прашање?"}])

    assert result.text == "Одговор."  # stripped
    assert result.tokens == 7


def test_chat_generation_is_greedy_and_bounded():
    fake = FakeLlama()
    instance = engine(fake, max_output_tokens=192)

    instance.generate_chat([{"role": "user", "content": "q"}])

    assert fake.chat_calls[0]["temperature"] == 0.0
    assert fake.chat_calls[0]["max_tokens"] == 192


@pytest.mark.parametrize("method", ["generate_chat", "generate"])
def test_repetition_penalty_is_disabled(method):
    """llama.cpp defaults to repeat_penalty=1.1, tuned for open-ended chat. On
    Macedonian legal prose, which repeats fixed statutory phrases by design, it
    drove the model into Russian forms and made greedy decoding non-reproducible.
    Same rationale as no_repeat_ngram_size being off for causal models."""
    fake = FakeLlama()
    instance = engine(fake)

    getattr(instance, method)([{"role": "user", "content": "q"}] if method == "generate_chat" else "p")

    calls = fake.chat_calls if method == "generate_chat" else fake.completion_calls
    assert calls[0]["repeat_penalty"] == 1.0


def test_messages_reach_llama_untouched_so_the_gguf_template_applies():
    fake = FakeLlama()
    messages = [{"role": "system", "content": "систем"}, {"role": "user", "content": "прашање"}]

    engine(fake).generate_chat(messages)

    assert fake.chat_calls[0]["messages"] == messages


def test_chat_stream_yields_only_content_deltas():
    instance = engine(FakeLlama(content="Работникот има право"))

    assert list(instance.generate_chat_stream([{"role": "user", "content": "q"}])) == [
        "Работникот",
        "има",
        "право",
    ]


def test_missing_content_is_not_an_error():
    """A chat completion can legitimately come back with a null content field."""
    fake = FakeLlama()
    fake.create_chat_completion = lambda messages, stream=False, **kw: {
        "choices": [{"message": {"content": None}}],
        "usage": {},
    }

    result = engine(fake).generate_chat([{"role": "user", "content": "q"}])

    assert result.text == ""
    assert result.tokens == 0


def test_plain_completion_path_used_by_non_chat_styles():
    fake = FakeLlama(content="clue text")

    result = engine(fake).generate("Write a clue:")

    assert result.text == "clue text"
    assert fake.completion_calls[0]["prompt"] == "Write a clue:"


def test_token_count_excludes_bos():
    instance = engine()

    assert instance.token_count("abc") == 3


@pytest.mark.parametrize("method", ["generate_chat", "generate"])
def test_generation_requires_a_loaded_model(method):
    instance = LlamaEngine(repo_id="r", filename="*.gguf")

    with pytest.raises(RuntimeError, match="not loaded"):
        getattr(instance, method)([] if method == "generate_chat" else "prompt")

"""In-process gRPC tests for the Generation Service using a fake engine, so the
servicer + wire contract are covered without loading a real checkpoint. A
separate smoke test (test_api_smoke.py) exercises the real model when present."""

import grpc
import pytest
from grpc_health.v1 import health_pb2, health_pb2_grpc

from app.main import GenerationServicer, build_server
from app.model import GenerationResult
from app.pb import generation_pb2, generation_pb2_grpc


class FakeEngine:
    """Stands in for GenerationEngine: records the prompt, returns a canned result."""

    def __init__(self):
        self.last_prompt = None
        self.last_messages = None
        self.loaded = True

    def apply_chat_template(self, messages: list[dict]) -> str:
        self.last_messages = messages
        return "\n".join(f"<|{m['role']}|>{m['content']}" for m in messages)

    def generate(self, prompt: str) -> GenerationResult:
        self.last_prompt = prompt
        return GenerationResult(text="a generated answer", tokens=5)

    def generate_stream(self, prompt: str):
        self.last_prompt = prompt
        for piece in ["a ", "generated ", "answer"]:
            yield piece

    # Mirrors GenerationEngine: the chat path renders the template, then
    # generates. LlamaEngine instead hands the messages straight to llama.cpp,
    # which applies the template embedded in the GGUF.
    def generate_chat(self, messages: list[dict]) -> GenerationResult:
        return self.generate(self.apply_chat_template(messages))

    def generate_chat_stream(self, messages: list[dict]):
        yield from self.generate_stream(self.apply_chat_template(messages))

    def token_count(self, text: str) -> int:
        return len(text.split())


@pytest.fixture()
def engine():
    return FakeEngine()


@pytest.fixture()
def channel(engine):
    server = build_server(engine, prompt_style="qa")
    port = server.add_insecure_port("localhost:0")
    server.start()
    with grpc.insecure_channel(f"localhost:{port}") as chan:
        yield chan
    server.stop(None)


def test_health_reports_serving(channel):
    response = health_pb2_grpc.HealthStub(channel).Check(health_pb2.HealthCheckRequest(service=""))
    assert response.status == health_pb2.HealthCheckResponse.SERVING


def test_generate_returns_answer(channel):
    stub = generation_pb2_grpc.GenerationStub(channel)
    response = stub.Generate(
        generation_pb2.GenerateRequest(
            question="Who is Albert Einstein?",
            context="albert einstein developed theory of relativity",
            entity="albert einstein",
        )
    )
    assert response.answer == "a generated answer"
    assert response.tokens == 5
    assert response.latency_ms >= 0
    assert response.model  # non-empty model name


def test_generate_forwards_context_into_prompt(engine):
    servicer = GenerationServicer(engine, prompt_style="qa")

    class _Ctx:
        def abort(self, code, details):  # pragma: no cover - not reached on valid input
            raise AssertionError(f"unexpected abort: {code} {details}")

    servicer.Generate(
        generation_pb2.GenerateRequest(question="Who is Einstein?", context="einstein born_in ulm"),
        _Ctx(),
    )
    assert "einstein born_in ulm" in engine.last_prompt


def test_generate_empty_question_is_invalid_argument(channel):
    stub = generation_pb2_grpc.GenerationStub(channel)
    with pytest.raises(grpc.RpcError) as exc:
        stub.Generate(generation_pb2.GenerateRequest(question=""))
    assert exc.value.code() == grpc.StatusCode.INVALID_ARGUMENT


def test_generate_stream_yields_chunks_then_done(channel):
    stub = generation_pb2_grpc.GenerationStub(channel)
    chunks = list(
        stub.GenerateStream(
            generation_pb2.GenerateRequest(question="Who is Einstein?", context="einstein born_in ulm")
        )
    )
    text = "".join(c.text for c in chunks if not c.done)
    assert text == "a generated answer"
    assert chunks[-1].done is True
    assert chunks[-1].tokens == 3  # "a generated answer" -> 3 words in the fake


def test_generate_stream_empty_question_is_invalid_argument(channel):
    stub = generation_pb2_grpc.GenerationStub(channel)
    with pytest.raises(grpc.RpcError) as exc:
        list(stub.GenerateStream(generation_pb2.GenerateRequest(question="")))
    assert exc.value.code() == grpc.StatusCode.INVALID_ARGUMENT


class _Ctx:
    def abort(self, code, details):  # pragma: no cover - not reached on valid input
        raise AssertionError(f"unexpected abort: {code} {details}")


def test_chat_style_renders_through_the_models_own_template(engine):
    """A chat model given a raw string prompt answers noticeably worse than one
    given its template, so the chat style must go through the tokenizer."""
    servicer = GenerationServicer(engine, prompt_style="chat")

    servicer.Generate(
        generation_pb2.GenerateRequest(
            question="Колку трае годишниот одмор?",
            context="[ZAKON-A, чл. 2]\nГодишен одмор",
        ),
        _Ctx(),
    )

    assert engine.last_messages is not None
    assert engine.last_messages[0]["role"] == "system"
    assert "[ZAKON-A, чл. 2]" in engine.last_messages[-1]["content"]
    assert engine.last_prompt.startswith("<|system|>")


def test_chat_style_forwards_history(engine):
    servicer = GenerationServicer(engine, prompt_style="chat")

    servicer.Generate(
        generation_pb2.GenerateRequest(
            question="А кој ја спроведува?",
            context="контекст",
            history=[generation_pb2.HistoryMessage(role="user", content="Што е јавна набавка?")],
        ),
        _Ctx(),
    )

    assert [message["role"] for message in engine.last_messages] == ["system", "user", "user"]


def test_chat_style_stream_omits_history(engine):
    """Streaming deliberately drops prior turns — same rationale as the unary path."""
    servicer = GenerationServicer(engine, prompt_style="chat")

    list(
        servicer.GenerateStream(
            generation_pb2.GenerateRequest(
                question="Прашање?",
                context="контекст",
                history=[generation_pb2.HistoryMessage(role="user", content="претходно")],
            ),
            _Ctx(),
        )
    )

    assert [message["role"] for message in engine.last_messages] == ["system", "user"]


def test_non_chat_style_never_touches_the_chat_template(engine):
    servicer = GenerationServicer(engine, prompt_style="qa")

    servicer.Generate(
        generation_pb2.GenerateRequest(question="Who is Einstein?", context="einstein born_in ulm"),
        _Ctx(),
    )

    assert engine.last_messages is None
    assert "einstein born_in ulm" in engine.last_prompt

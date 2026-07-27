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
        self.loaded = True

    def generate(self, prompt: str) -> GenerationResult:
        self.last_prompt = prompt
        return GenerationResult(text="a generated answer", tokens=5)

    def generate_stream(self, prompt: str):
        self.last_prompt = prompt
        for piece in ["a ", "generated ", "answer"]:
            yield piece

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

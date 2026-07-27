"""Smoke test against the real checkpoint over gRPC. Skipped unless MODEL_PATH
points at an existing directory (the weights are ~110 MB and live outside the
repo)."""

import os

import grpc
import pytest
from grpc_health.v1 import health_pb2, health_pb2_grpc

MODEL_PATH = os.environ.get("MODEL_PATH", "")

pytestmark = pytest.mark.skipif(
    not os.path.isdir(MODEL_PATH),
    reason="MODEL_PATH does not point at a checkpoint directory",
)


@pytest.fixture(scope="module")
def channel():
    from app.config import get_settings
    from app.main import build_server
    from app.model import GenerationEngine

    settings = get_settings()
    engine = GenerationEngine(
        model_path=settings.model_path,
        device=settings.device,
        max_input_tokens=settings.max_input_tokens,
        max_output_tokens=settings.max_output_tokens,
        num_beams=settings.num_beams,
    )
    engine.load()
    server = build_server(engine, settings.prompt_style)
    port = server.add_insecure_port("localhost:0")
    server.start()
    with grpc.insecure_channel(f"localhost:{port}") as chan:
        yield chan
    server.stop(None)


def test_health_reports_serving(channel):
    response = health_pb2_grpc.HealthStub(channel).Check(health_pb2.HealthCheckRequest(service=""))
    assert response.status == health_pb2.HealthCheckResponse.SERVING


def test_generate_returns_answer(channel):
    from app.pb import generation_pb2, generation_pb2_grpc

    response = generation_pb2_grpc.GenerationStub(channel).Generate(
        generation_pb2.GenerateRequest(
            question="Who is Albert Einstein?",
            context="albert einstein developed theory of relativity | albert einstein born_in ulm",
            entity="albert einstein",
        )
    )
    assert response.model == os.environ.get("MODEL_NAME", "bart-jeopardy-final")
    assert len(response.answer) > 0
    assert response.tokens > 0
    assert response.latency_ms >= 0

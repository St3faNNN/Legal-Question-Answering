"""In-process gRPC tests for the Retrieval Service: a real server on an
ephemeral port with a real channel, exercising the wire contract end to end."""

import grpc
import pytest
from grpc_health.v1 import health_pb2, health_pb2_grpc

from app.config import get_settings
from app.main import RetrievalContext, build_server
from app.pb import retrieval_pb2, retrieval_pb2_grpc


@pytest.fixture()
def channel(graphml_path, monkeypatch):
    monkeypatch.setenv("GRAPH_PATH", graphml_path)
    context = RetrievalContext(get_settings())
    server = build_server(context)
    port = server.add_insecure_port("localhost:0")
    server.start()
    with grpc.insecure_channel(f"localhost:{port}") as chan:
        yield chan
    server.stop(None)


def test_health_reports_serving(channel):
    response = health_pb2_grpc.HealthStub(channel).Check(health_pb2.HealthCheckRequest(service=""))
    assert response.status == health_pb2.HealthCheckResponse.SERVING


def test_retrieve_happy_path(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    response = stub.Retrieve(retrieval_pb2.RetrieveRequest(question="Who is Albert Einstein?"))

    assert list(response.entities) == ["albert einstein"]
    assert "albert einstein" in response.context
    assert " | " in response.context
    assert len(response.triples) > 0
    first = response.triples[0]
    assert first.head and first.relation and first.tail
    assert isinstance(first.confidence, float)
    assert isinstance(response.latency_ms, int)


def test_retrieve_unknown_entity_returns_empty_context(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    response = stub.Retrieve(retrieval_pb2.RetrieveRequest(question="qwzx flimflam borogoves?"))

    assert response.context == ""
    assert list(response.entities) == []
    assert len(response.triples) == 0


def test_retrieve_empty_question_is_invalid_argument(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    with pytest.raises(grpc.RpcError) as exc:
        stub.Retrieve(retrieval_pb2.RetrieveRequest(question=""))
    assert exc.value.code() == grpc.StatusCode.INVALID_ARGUMENT

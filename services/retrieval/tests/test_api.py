"""In-process gRPC tests for the Retrieval Service: a real server on an
ephemeral port with a real channel, exercising the hybrid pipeline and the wire
contract end to end. The two transformer models are faked (see conftest), so no
test downloads weights."""

import grpc
import numpy as np
import pytest
from grpc_health.v1 import health_pb2, health_pb2_grpc

from app.citations import load_citation_graph
from app.config import get_settings
from app.main import RetrievalContext, build_server
from app.pb import retrieval_pb2, retrieval_pb2_grpc
from app.vectors import VectorStore
from tests.conftest import FakeEmbedder


@pytest.fixture()
def make_context(vector_db_path, citation_graphml_path, monkeypatch):
    """Builds a RetrievalContext over the fixture corpus with injectable
    embedder double."""

    def _build(embedder=None, **env) -> RetrievalContext:
        monkeypatch.setenv("GRAPH_PATH", citation_graphml_path)
        monkeypatch.setenv("VECTOR_DB_PATH", vector_db_path)
        for key, value in env.items():
            monkeypatch.setenv(key, str(value))

        store = VectorStore(vector_db_path)
        store.load()
        return RetrievalContext(
            get_settings(),
            store,
            load_citation_graph(citation_graphml_path),
            embedder or FakeEmbedder(),
        )

    return _build


@pytest.fixture()
def context(make_context) -> RetrievalContext:
    return make_context()


@pytest.fixture()
def channel(context):
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
    response = stub.Retrieve(retrieval_pb2.RetrieveRequest(question="Работно време?"))

    assert len(response.chunks) > 0
    assert response.context
    assert response.context_items > 0
    assert isinstance(response.latency_ms, int)

    first = response.chunks[0]
    assert first.id and first.text and first.source
    assert isinstance(first.score, float)


def test_retrieve_reports_citations_as_entities(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    response = stub.Retrieve(retrieval_pb2.RetrieveRequest(question="Работно време?"))

    assert "ZAKON-A, чл. 1" in list(response.entities)


def test_retrieve_packs_the_returned_passages_into_the_context(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    response = stub.Retrieve(retrieval_pb2.RetrieveRequest(question="Работно време?"))

    assert "Работно време" in response.context
    assert "[ZAKON-A, чл. 1]" in response.context


def test_retrieve_empty_question_is_invalid_argument(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    with pytest.raises(grpc.RpcError) as exc:
        stub.Retrieve(retrieval_pb2.RetrieveRequest(question=""))
    assert exc.value.code() == grpc.StatusCode.INVALID_ARGUMENT


def test_references_returns_outgoing_citations(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    response = stub.References(retrieval_pb2.ReferencesRequest(law="ZAKON-A", article="1"))

    assert response.found is True
    assert response.source_citation == "ZAKON-A, чл. 1"
    assert [reference.citation for reference in response.references] == ["ZAKON-A, чл. 3"]
    assert response.references[0].confidence == 1.0


def test_references_for_an_article_that_cites_nothing_is_empty(channel):
    """Article 3 is cited BY article 1 but cites nothing itself."""
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    response = stub.References(retrieval_pb2.ReferencesRequest(law="ZAKON-A", article="3"))

    assert response.found is True
    assert list(response.references) == []


def test_references_for_an_unknown_article_reports_not_found(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    response = stub.References(retrieval_pb2.ReferencesRequest(law="NEMA-ZAKON", article="9"))

    assert response.found is False
    assert response.source_citation == ""
    assert list(response.references) == []


def test_references_empty_arguments_are_invalid(channel):
    stub = retrieval_pb2_grpc.RetrievalStub(channel)
    with pytest.raises(grpc.RpcError) as exc:
        stub.References(retrieval_pb2.ReferencesRequest(law="ZAKON-A", article=""))
    assert exc.value.code() == grpc.StatusCode.INVALID_ARGUMENT


# --- Pipeline behaviour (direct on the context, no wire) ----------------------


def test_graph_expansion_pulls_in_a_cross_referenced_article(make_context):
    """The whole point of the graph arm: article 1 cites article 3, so a query
    that only matches article 1 must still surface article 3."""
    embedder = FakeEmbedder(dense=[1.0, 0.0, 0.0, 0.0])
    context = make_context(embedder=embedder)

    result = context.retrieve("Работно време?")

    units = {chunk["source"] for chunk in result["chunks"]}
    assert "ZAKON-A, чл. 1" in units  # the direct vector hit
    assert "ZAKON-A, чл. 3" in units  # reached only via the citation graph


def test_cross_references_are_reported_as_triples(make_context):
    context = make_context(embedder=FakeEmbedder(dense=[1.0, 0.0, 0.0, 0.0]))

    result = context.retrieve("Работно време?")

    assert {
        "head": "ZAKON-A, чл. 1",
        "relation": "упатува на",
        "tail": "ZAKON-A, чл. 3",
        "confidence": 1.0,
    } in result["triples"]


def test_sparse_arm_contributes_when_the_embedder_returns_weights(make_context):
    """Token 14 appears only on the two zakon-b chunks, which the dense query
    direction does not favour."""
    embedder = FakeEmbedder(dense=[1.0, 0.0, 0.0, 0.0], sparse={14: 1.0})
    context = make_context(embedder=embedder)

    result = context.retrieve("Јавни набавки?")

    sources = {chunk["source"] for chunk in result["chunks"]}
    assert "ZAKON-B, чл. 1" in sources


def test_retrieval_top_n_caps_what_reaches_the_generator(make_context):
    context = make_context(RETRIEVAL_TOP_N=2)

    result = context.retrieve("Работно време?")

    assert len(result["chunks"]) == 2


def test_context_items_reports_what_survived_the_budget(make_context):
    """The returned set and the set the generator actually sees can differ; the
    gap must be visible rather than silently absorbed."""
    context = make_context(CONTEXT_CHAR_BUDGET=40)

    result = context.retrieve("Работно време?")

    assert len(result["chunks"]) > result["context_items"] >= 1


def test_retrieve_returns_a_score_per_chunk(make_context):
    # The score used to be the FakeReranker's synthetic 100.0. With the
    # cross-encoder gone the only score is the RRF fused score, so the invariant
    # is that every chunk carries a positive one — not a particular magnitude.
    context = make_context()

    result = context.retrieve("Плата?")

    assert result["chunks"]
    assert all(chunk["score"] > 0 for chunk in result["chunks"])


def test_empty_vector_matches_still_produce_a_valid_response(make_context):
    """A query orthogonal to every chunk must return a well-formed empty-ish
    result, not raise."""
    embedder = FakeEmbedder(dense=[0.0, 0.0, 0.0, 0.0])
    context = make_context(embedder=embedder)

    result = context.retrieve("нешто сосема друго")

    assert isinstance(result["chunks"], list)
    assert isinstance(result["context"], str)
    assert isinstance(result["entities"], list)
    assert isinstance(result["latency_ms"], int)


def test_question_is_forwarded_to_both_models(make_context):
    embedder = FakeEmbedder()
    context = make_context(embedder=embedder)

    context.retrieve("Колку трае годишниот одмор?")

    assert embedder.encoded == ["Колку трае годишниот одмор?"]


def test_dense_only_when_the_sparse_head_is_unavailable(make_context):
    """Losing the sparse arm is a quality reduction, not an outage."""
    context = make_context(embedder=FakeEmbedder(dense=[1.0, 0.0, 0.0, 0.0], sparse={}))

    result = context.retrieve("Работно време?")

    assert len(result["chunks"]) > 0


def test_vector_store_dimension_matches_the_fixture(vector_db_path):
    store = VectorStore(vector_db_path)
    store.load()

    assert store.dense.shape == (5, 4)
    assert np.allclose(np.linalg.norm(store.dense, axis=1), 1.0)

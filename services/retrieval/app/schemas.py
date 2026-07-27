"""Request/response models for the Retrieval Service API."""

from pydantic import BaseModel, Field


class RetrieveRequest(BaseModel):
    question: str = Field(min_length=1, max_length=4000)


class Triple(BaseModel):
    head: str
    relation: str
    tail: str
    confidence: float


class RetrieveResponse(BaseModel):
    context: str
    entities: list[str]
    triples: list[Triple]
    latency_ms: int


class HealthResponse(BaseModel):
    status: str
    graph_loaded: bool
    nodes: int
    edges: int

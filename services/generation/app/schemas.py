"""Request/response models for the Generation Service API.

`entity` is an additive extension of the platform contract: the Retrieval
Service's linked entity fills the prompt's `Correct response:` slot (the
distribution the checkpoint was trained on). It is optional; omitting it
falls back to using the question as the prompt subject.
"""

from pydantic import BaseModel, ConfigDict, Field

from .config import get_model_name


class HistoryMessage(BaseModel):
    role: str  # "user" | "assistant"
    content: str


class GenerateRequest(BaseModel):
    question: str = Field(min_length=1)
    context: str = ""
    entity: str | None = None
    history: list[HistoryMessage] = []


class GenerateResponse(BaseModel):
    model_config = ConfigDict(protected_namespaces=())

    answer: str
    model: str = Field(default_factory=get_model_name)
    tokens: int
    latency_ms: int


class HealthResponse(BaseModel):
    model_config = ConfigDict(protected_namespaces=())

    status: str
    model_loaded: bool
    device: str

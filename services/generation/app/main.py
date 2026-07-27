"""GraphRAG Generation Service (gRPC): question + context in, generated answer out.

The seq2seq checkpoint is loaded once at server startup (never per request) —
`build_server` is only reached after `engine.load()` succeeds, and the gRPC
health service is flipped to SERVING at that point so dependents wait for a
ready model.
"""

import logging
import os
import time
from concurrent import futures

import grpc
from grpc_health.v1 import health, health_pb2, health_pb2_grpc

from .config import get_model_name, get_settings
from .model import GenerationEngine
from .pb import generation_pb2, generation_pb2_grpc
from .prompt import build_prompt

logger = logging.getLogger("generation")

SERVICE_NAME = "graphrag.generation.v1.Generation"


class GenerationServicer(generation_pb2_grpc.GenerationServicer):
    def __init__(self, engine: GenerationEngine, prompt_style: str):
        self._engine = engine
        self._prompt_style = prompt_style

    def Generate(self, request, grpc_context):
        if not request.question or not request.question.strip():
            grpc_context.abort(grpc.StatusCode.INVALID_ARGUMENT, "question must not be empty")

        start = time.perf_counter()
        prompt = build_prompt(
            request.question,
            request.context,
            request.entity or None,
            self._prompt_style,
            [{"role": message.role, "content": message.content} for message in request.history],
        )
        result = self._engine.generate(prompt)
        return generation_pb2.GenerateResponse(
            answer=result.text,
            model=get_model_name(),
            tokens=result.tokens,
            latency_ms=round((time.perf_counter() - start) * 1000),
        )

    def GenerateStream(self, request, grpc_context):
        if not request.question or not request.question.strip():
            grpc_context.abort(grpc.StatusCode.INVALID_ARGUMENT, "question must not be empty")

        prompt = build_prompt(
            request.question,
            request.context,
            request.entity or None,
            self._prompt_style,
            [],  # history omitted: the small model copies prior answers otherwise
        )

        full = ""
        for text in self._engine.generate_stream(prompt):
            full += text
            yield generation_pb2.GenerateChunk(text=text, done=False, tokens=0)

        yield generation_pb2.GenerateChunk(
            text="", done=True, tokens=self._engine.token_count(full), model=get_model_name()
        )


def build_server(engine: GenerationEngine, prompt_style: str, max_workers: int = 4) -> grpc.Server:
    """Assembles a gRPC server with the Generation and standard Health services.
    Call only after the engine is loaded; the caller binds a port and starts it."""
    server = grpc.server(futures.ThreadPoolExecutor(max_workers=max_workers))
    generation_pb2_grpc.add_GenerationServicer_to_server(
        GenerationServicer(engine, prompt_style), server
    )

    health_servicer = health.HealthServicer()
    health_pb2_grpc.add_HealthServicer_to_server(health_servicer, server)
    health_servicer.set("", health_pb2.HealthCheckResponse.SERVING)
    health_servicer.set(SERVICE_NAME, health_pb2.HealthCheckResponse.SERVING)

    return server


def serve() -> None:
    logging.basicConfig(level=logging.INFO)
    settings = get_settings()

    engine = GenerationEngine(
        model_path=settings.model_path,
        device=settings.device,
        max_input_tokens=settings.max_input_tokens,
        max_output_tokens=settings.max_output_tokens,
        num_beams=settings.num_beams,
    )
    engine.load()
    build_prompt("startup check", "", None, settings.prompt_style)  # reject unknown styles early

    server = build_server(
        engine, settings.prompt_style, max_workers=int(os.environ.get("GRPC_MAX_WORKERS", "4"))
    )
    port = os.environ.get("PORT", "8002")
    server.add_insecure_port(f"[::]:{port}")
    server.start()
    logger.info("Generation gRPC server listening on :%s", port)
    server.wait_for_termination()


if __name__ == "__main__":
    serve()

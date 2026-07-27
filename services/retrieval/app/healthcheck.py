"""Container HEALTHCHECK: exit 0 when the gRPC health service reports SERVING.

Used in place of an HTTP probe now that the service speaks gRPC. Keeps the
Dockerfile HEALTHCHECK to a single `python -m app.healthcheck` invocation.
"""

import os
import sys

import grpc
from grpc_health.v1 import health_pb2, health_pb2_grpc


def main() -> int:
    port = os.environ.get("PORT", "8001")
    try:
        channel = grpc.insecure_channel(f"localhost:{port}")
        response = health_pb2_grpc.HealthStub(channel).Check(
            health_pb2.HealthCheckRequest(service=""), timeout=3
        )
    except grpc.RpcError:
        return 1
    return 0 if response.status == health_pb2.HealthCheckResponse.SERVING else 1


if __name__ == "__main__":
    sys.exit(main())

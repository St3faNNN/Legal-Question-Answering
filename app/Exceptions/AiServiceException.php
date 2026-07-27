<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when one of the AI microservices (Retrieval or Generation) cannot
 * produce a usable response. The user's message is already persisted by the
 * time reply generation runs, so callers can surface this as a clean
 * "service unavailable" error without losing conversation state.
 */
class AiServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $service,
        public readonly string $reason,
        string $message,
        public readonly ?int $upstreamStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionFailed(string $service, Throwable $previous): self
    {
        return new self(
            service: $service,
            reason: 'connection',
            message: "Could not reach the {$service} service: {$previous->getMessage()}",
            previous: $previous,
        );
    }

    public static function httpError(string $service, int $status): self
    {
        return new self(
            service: $service,
            reason: 'http_error',
            message: "The {$service} service responded with HTTP {$status}.",
            upstreamStatus: $status,
        );
    }

    public static function invalidResponse(string $service, string $detail): self
    {
        return new self(
            service: $service,
            reason: 'invalid_response',
            message: "The {$service} service returned an invalid response: {$detail}",
        );
    }
}

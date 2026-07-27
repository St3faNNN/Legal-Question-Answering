<?php

namespace App\Grpc;

use App\Exceptions\AiServiceException;
use App\Grpc\Pb\Generation\GenerateRequest;
use App\Grpc\Pb\Generation\GenerateResponse;
use App\Grpc\Pb\Generation\GenerationClient as GenerationStub;
use App\Grpc\Pb\Generation\HistoryMessage;
use Grpc\ChannelCredentials;

/**
 * gRPC-backed {@see GenerationClient}. Wraps the generated protobuf stub and
 * translates transport failures into {@see AiServiceException}. Only
 * instantiated under the grpc driver (Docker).
 */
class GrpcGenerationClient implements GenerationClient
{
    private GenerationStub $stub;

    public function __construct(string $target, private readonly int $timeout = 30)
    {
        $this->stub = new GenerationStub($target, [
            'credentials' => ChannelCredentials::createInsecure(),
        ]);
    }

    public function generate(string $question, string $context, ?string $entity, array $history): array
    {
        $request = (new GenerateRequest())
            ->setQuestion($question)
            ->setContext($context)
            ->setEntity($entity ?? '')
            ->setHistory(array_map(
                fn (array $message): HistoryMessage => (new HistoryMessage())
                    ->setRole($message['role'])
                    ->setContent($message['content']),
                $history,
            ));

        /** @var array{0: ?GenerateResponse, 1: object} $result */
        $result = $this->stub
            ->Generate($request, [], ['timeout' => $this->timeout * 1_000_000])
            ->wait();

        [$response, $status] = $result;

        if ($status->code !== \Grpc\STATUS_OK || $response === null) {
            throw $this->mapStatus($status);
        }

        return [
            'answer' => $response->getAnswer(),
            'model' => $response->getModel(),
            'tokens' => $response->getTokens(),
            'latency_ms' => $response->getLatencyMs(),
        ];
    }

    public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
    {
        $request = (new GenerateRequest())
            ->setQuestion($question)
            ->setContext($context)
            ->setEntity($entity ?? '')
            ->setHistory(array_map(
                fn (array $message): HistoryMessage => (new HistoryMessage())
                    ->setRole($message['role'])
                    ->setContent($message['content']),
                $history,
            ));

        $call = $this->stub->GenerateStream($request, [], ['timeout' => $this->timeout * 1_000_000]);

        foreach ($call->responses() as $chunk) {
            yield [
                'text' => $chunk->getText(),
                'done' => $chunk->getDone(),
                'tokens' => $chunk->getTokens(),
                'model' => $chunk->getModel(),
            ];
        }

        // For a server-streaming call the status is available only after the
        // response iterator is exhausted.
        $status = $call->getStatus();

        if ($status->code !== \Grpc\STATUS_OK) {
            throw $this->mapStatus($status);
        }
    }

    private function mapStatus(object $status): AiServiceException
    {
        $transport = [\Grpc\STATUS_UNAVAILABLE, \Grpc\STATUS_DEADLINE_EXCEEDED, \Grpc\STATUS_CANCELLED];

        if (in_array($status->code, $transport, true)) {
            return AiServiceException::connectionFailed(
                'generation',
                new \RuntimeException($status->details ?: 'gRPC transport error'),
            );
        }

        return AiServiceException::invalidResponse('generation', "gRPC status {$status->code}: {$status->details}");
    }
}

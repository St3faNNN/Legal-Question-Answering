<?php

namespace App\Grpc;

use App\Exceptions\AiServiceException;
use App\Grpc\Pb\Retrieval\ReferencesRequest;
use App\Grpc\Pb\Retrieval\ReferencesResponse;
use App\Grpc\Pb\Retrieval\RetrievalClient as RetrievalStub;
use App\Grpc\Pb\Retrieval\RetrieveRequest;
use App\Grpc\Pb\Retrieval\RetrieveResponse;
use Grpc\ChannelCredentials;

/**
 * gRPC-backed {@see RetrievalClient}. Wraps the generated protobuf stub and
 * translates transport failures into {@see AiServiceException}. Instantiated
 * wherever the app runs for real — in practice Docker, the only place the PHP
 * grpc extension and the generated stubs under App\Grpc\Pb are present.
 * Outside a container this binding is never resolved, because tests bind a fake
 * {@see RetrievalClient} instead.
 */
class GrpcRetrievalClient implements RetrievalClient
{
    private RetrievalStub $stub;

    public function __construct(string $target, private readonly int $timeout = 30)
    {
        $this->stub = new RetrievalStub($target, [
            'credentials' => ChannelCredentials::createInsecure(),
        ]);
    }

    public function retrieve(string $question): array
    {
        $request = (new RetrieveRequest)->setQuestion($question);

        /** @var array{0: ?RetrieveResponse, 1: object} $result */
        $result = $this->stub
            ->Retrieve($request, [], ['timeout' => $this->timeout * 1_000_000])
            ->wait();

        [$response, $status] = $result;

        if ($status->code !== \Grpc\STATUS_OK || $response === null) {
            throw $this->mapStatus($status);
        }

        return [
            'context' => $response->getContext(),
            'entities' => iterator_to_array($response->getEntities()),
            'triples' => array_map(
                fn ($triple): array => [
                    'head' => $triple->getHead(),
                    'relation' => $triple->getRelation(),
                    'tail' => $triple->getTail(),
                    'confidence' => $triple->getConfidence(),
                ],
                iterator_to_array($response->getTriples()),
            ),
            'chunks' => array_map(
                fn ($chunk): array => [
                    'id' => $chunk->getId(),
                    'text' => $chunk->getText(),
                    'source' => $chunk->getSource(),
                    'score' => $chunk->getScore(),
                ],
                iterator_to_array($response->getChunks()),
            ),
            'context_items' => $response->getContextItems(),
            'latency_ms' => $response->getLatencyMs(),
        ];
    }

    public function references(string $law, string $article): array
    {
        $request = (new ReferencesRequest)->setLaw($law)->setArticle($article);

        /** @var array{0: ?ReferencesResponse, 1: object} $result */
        $result = $this->stub
            ->References($request, [], ['timeout' => $this->timeout * 1_000_000])
            ->wait();

        [$response, $status] = $result;

        if ($status->code !== \Grpc\STATUS_OK || $response === null) {
            throw $this->mapStatus($status);
        }

        return [
            'found' => $response->getFound(),
            'source_citation' => $response->getSourceCitation(),
            'references' => array_map(
                fn ($reference): array => [
                    'citation' => $reference->getCitation(),
                    'heading' => $reference->getHeading(),
                    'confidence' => $reference->getConfidence(),
                ],
                iterator_to_array($response->getReferences()),
            ),
        ];
    }

    private function mapStatus(object $status): AiServiceException
    {
        // Transport-level failures (peer down, deadline exceeded) mirror the
        // HTTP driver's "connection" reason; anything else is an invalid response.
        $transport = [\Grpc\STATUS_UNAVAILABLE, \Grpc\STATUS_DEADLINE_EXCEEDED, \Grpc\STATUS_CANCELLED];

        if (in_array($status->code, $transport, true)) {
            return AiServiceException::connectionFailed(
                'retrieval',
                new \RuntimeException($status->details ?: 'gRPC transport error'),
            );
        }

        return AiServiceException::invalidResponse('retrieval', "gRPC status {$status->code}: {$status->details}");
    }
}

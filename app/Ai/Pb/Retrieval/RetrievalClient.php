<?php
// GENERATED CODE -- DO NOT EDIT!

namespace App\Ai\Pb\Retrieval;

/**
 * Retrieval Service: a natural-language question in, linked knowledge-graph
 * context out. No language generation, no knowledge of users or conversations.
 */
class RetrievalClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \App\Ai\Pb\Retrieval\RetrieveRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function Retrieve(\App\Ai\Pb\Retrieval\RetrieveRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/graphrag.retrieval.v1.Retrieval/Retrieve',
        $argument,
        ['\App\Ai\Pb\Retrieval\RetrieveResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Outgoing citations of a single article: the articles it points at
     * ("as defined in Article 28…"). Pure graph traversal, no vector search.
     * @param \App\Ai\Pb\Retrieval\ReferencesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function References(\App\Ai\Pb\Retrieval\ReferencesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/graphrag.retrieval.v1.Retrieval/References',
        $argument,
        ['\App\Ai\Pb\Retrieval\ReferencesResponse', 'decode'],
        $metadata, $options);
    }

}

<?php

declare(strict_types=1);

namespace Smithy\Protocol;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Contract for protocols that serialize operations to HTTP messages.
 *
 * RPC-style protocols determine the wire shape from their own conventions
 * plus the codec's body serialization. HTTP binding protocols use operation
 * and member schema extensions to drive path, query, header, payload, and
 * body routing.
 */
interface ProtocolInterface
{
    /**
     * Return the Smithy protocol trait shape ID.
     */
    public function getId(): string;

    /**
     * Serialize an operation input into an HTTP request.
     *
     * @param OperationInterface $operation The operation singleton.
     * @param SerializableStructInterface $input The input shape.
     * @param string $endpoint The base endpoint URL.
     *
     * @return RequestInterface The serialized HTTP request.
     */
    public function serializeRequest(
        OperationInterface $operation,
        SerializableStructInterface $input,
        string $endpoint
    ): RequestInterface;

    /**
     * Deserialize an HTTP response into an output shape.
     *
     * Throws when the response does not satisfy the protocol's success
     * contract or represents a modeled/unmodeled service error.
     *
     * @param OperationInterface $operation The operation singleton.
     * @param ResponseInterface $response The HTTP response.
     *
     * @return SerializableStructInterface The deserialized output shape.
     * @throws \Throwable On protocol or service error responses.
     */
    public function deserializeResponse(
        OperationInterface $operation,
        ResponseInterface $response
    ): SerializableStructInterface;
}

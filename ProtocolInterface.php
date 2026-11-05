<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol;

use Smithy\Client\Endpoint\Endpoint;
use Smithy\Client\Transport\{
    MessageExchangeInterface,
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Contract for protocols that serialize operations to transport messages.
 *
 * A protocol declares its message exchange so client configuration can
 * reject incompatible transports before execution.
 */
interface ProtocolInterface
{
    /**
     * Return the Smithy protocol trait shape ID.
     */
    public function getId(): string;

    /**
     * Return the request and response types used by this protocol.
     */
    public function getMessageExchange(): MessageExchangeInterface;

    /**
     * Serialize an operation input into a transport request.
     *
     * @param OperationInterface $operation The operation singleton.
     * @param SerializableStructInterface $input The input shape.
     */
    public function serializeRequest(
        OperationInterface $operation,
        SerializableStructInterface $input
    ): TransportRequestInterface;

    /**
     * Bind a resolved service endpoint and its headers to an
     * operation-relative request.
     */
    public function setServiceEndpoint(
        TransportRequestInterface $request,
        Endpoint $endpoint
    ): TransportRequestInterface;

    /**
     * Deserialize a transport response into an output shape.
     *
     * Throws when the response does not satisfy the protocol's success
     * contract or represents a modeled/unmodeled service error.
     *
     * @param OperationInterface $operation The operation singleton.
     * @return SerializableStructInterface The deserialized output shape.
     * @throws \Throwable On protocol or service error responses.
     */
    public function deserializeResponse(
        OperationInterface $operation,
        TransportResponseInterface $response
    ): SerializableStructInterface;
}

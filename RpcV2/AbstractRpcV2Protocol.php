<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol\RpcV2;

use Psr\Http\Message\ResponseInterface;
use Smithy\Core\Endpoint\Endpoint;
use Smithy\Core\Transport\{
    MessageExchangeInterface,
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;
use Smithy\Http\Message\{
    HttpMessageExchange,
    HttpTransportRequest,
    HttpTransportResponse,
    Request,
    Uri
};
use Smithy\Protocol\Exception\ProtocolException;
use Smithy\Protocol\HttpErrorDeserializer;
use Smithy\Protocol\ProtocolInterface;
use Smithy\Protocol\Utils\ComponentUri;

/**
 * Shared base for Smithy RPC v2 protocols.
 *
 * RPC v2 protocols ignore HTTP binding traits and instead send every request
 * as a POST to `/service/{Service}/operation/{Operation}` with the protocol
 * identified by the `Smithy-Protocol` header. Subclasses supply the concrete
 * codec and media type (`rpc-v2-cbor` / `application/cbor` today, and a JSON
 * variant later). Error envelope extraction is delegated to the
 * protocol's {@see HttpErrorDeserializer}.
 *
 * The hot request path caches each operation's derived wire metadata once:
 * the fixed request path and whether the input/output shapes are `Unit`.
 * That keeps repeated calls on a long-lived client down to codec work,
 * endpoint stitching, and a handful of header assignments.
 */
abstract class AbstractRpcV2Protocol implements ProtocolInterface
{
    protected const HEADER_ACCEPT = 'accept';
    protected const HEADER_CONTENT_TYPE = 'content-type';
    protected const HEADER_CONTENT_LENGTH = 'content-length';
    protected const HEADER_SMITHY_PROTOCOL = 'smithy-protocol';
    private const UNIT_SHAPE_ID = 'smithy.api#Unit';

    /**
     * @var string Smithy-Protocol header value for the concrete protocol.
     */
    protected static string $protocolHeaderValue;
    /**
     * @var string Buffered request and response media type.
     */
    protected static string $mediaType;

    /**
     * Cached per-operation wire info: `[path, inputIsUnit, outputIsUnit]`.
     *
     * @var array<string, array{0: string, 1: bool, 2: bool}>
     */
    private array $wireInfoByOperation = [];

    /**
     * Create an RPC v2 protocol with its payload codec.
     */
    public function __construct(
        protected readonly AbstractCodec $codec,
        private readonly HttpErrorDeserializer $errorDeserializer
    ) {}

    /** @inheritDoc */
    public function getMessageExchange(): MessageExchangeInterface
    {
        return HttpMessageExchange::instance();
    }

    /**
     * Serialize operation input into an RPC v2 transport request.
     */
    public function serializeRequest(
        OperationInterface $operation,
        SerializableStructInterface $input
    ): TransportRequestInterface
    {
        [$requestPath, $inputIsUnit] = $this->resolveWireInfo($operation);

        $headers = [
            self::HEADER_SMITHY_PROTOCOL => [
                'Smithy-Protocol',
                [static::$protocolHeaderValue]
            ],
            self::HEADER_ACCEPT => ['Accept', [static::$mediaType]]
        ];

        $body = '';
        if (!$inputIsUnit) {
            $serializer = $this->codec->acquireSerializer();
            try {
                $input->serialize($serializer, 0);
                $body = $serializer->flush();
            } finally {
                $this->codec->releaseSerializer($serializer);
            }

            $headers[self::HEADER_CONTENT_TYPE] = [
                'Content-Type',
                [static::$mediaType]
            ];
            $headers[self::HEADER_CONTENT_LENGTH] = [
                'Content-Length',
                [(string) \strlen($body)]
            ];
        }

        $uri = new ComponentUri(
            scheme: '',
            host: '',
            path: $requestPath
        );

        return new HttpTransportRequest(
            Request::fromNormalized(
                'POST',
                $uri,
                $headers,
                $body
            )
        );
    }

    public function setServiceEndpoint(
        TransportRequestInterface $request,
        Endpoint $endpoint
    ): TransportRequestInterface
    {
        $request = HttpTransportRequest::expect($request);
        $httpRequest = $request->request;
        $endpointUri = new Uri($endpoint->url);
        $requestUri = $httpRequest->getUri();
        $endpointPath = $endpointUri->getPath();
        $path = $endpointPath !== '' && $endpointPath !== '/'
            ? \rtrim($endpointPath, '/') . $requestUri->getPath()
            : $requestUri->getPath();

        return $request
            ->withRequest(
                $httpRequest->withUri(
                    $endpointUri
                        ->withPath($path)
                        ->withQuery($requestUri->getQuery())
                        ->withFragment('')
                        ->withUserInfo('')
                )
            )
            ->withEndpointHeaders($endpoint);
    }

    /**
     * Deserialize an RPC v2 HTTP response into operation output.
     */
    public function deserializeResponse(
        OperationInterface $operation,
        TransportResponseInterface $response
    ): SerializableStructInterface
    {
        $response = HttpTransportResponse::expect($response)->response;
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw $this->errorDeserializer->deserialize(
                $operation,
                $response
            );
        }

        if (!$this->hasExpectedProtocolHeader($response)) {
            throw new ProtocolException(
                'Malformed ' . static::$protocolHeaderValue
                . ' response: invalid Smithy-Protocol header for HTTP 200.'
            );
        }

        $outputSchema = $operation->getOutputSchema()->resolveTarget();
        [, , $outputIsUnit] = $this->resolveWireInfo($operation);
        if ($outputIsUnit) {
            return $operation->createOutput([]);
        }

        $contentLength = $response->getHeader('content-length')[0] ?? null;
        if ($contentLength !== null && (int) $contentLength === 0) {
            if ($outputSchema->getMemberCount() === 0) {
                return $operation->createOutput([]);
            }

            throw new ProtocolException(
                'Malformed ' . static::$protocolHeaderValue
                . ' response: missing body for non-Unit output.'
            );
        }

        $members = $this->codec->createMembersBuffer($outputSchema);

        $this->codec->createDeserializer($response->getBody())
            ->deserialize($outputSchema, $members);

        return $operation->createOutput($members);
    }

    /**
     * @return array{0: string, 1: bool, 2: bool}
     */
    private function resolveWireInfo(OperationInterface $operation): array
    {
        $runtimeIdentity = $operation->getRuntimeIdentity();

        return $this->wireInfoByOperation[$runtimeIdentity]
            ??= $this->buildWireInfo($operation);
    }

    /**
     * @return array{0: string, 1: bool, 2: bool}
     */
    private function buildWireInfo(OperationInterface $operation): array
    {
        $target = $operation->getRequestTarget();
        if ($target !== null) {
            $separator = \strrpos($target, '.');
            if ($separator === false
                || $separator === 0
                || $separator === \strlen($target) - 1
            ) {
                throw new ProtocolException(
                    'RPC v2 request targets must use the '
                        . '"<Service>.<Operation>" form.'
                );
            }

            $serviceName = \substr($target, 0, $separator);
            $operationName = \substr($target, $separator + 1);
        } else {
            $serviceId = $operation->getServiceId();
            if ($serviceId === null) {
                throw new ProtocolException(
                    'RPC v2 operations require a request target or '
                        . 'service shape ID.'
                );
            }
            $separator = \strrpos($serviceId, '#');
            $serviceName = $separator === false
                ? $serviceId
                : \substr($serviceId, $separator + 1);
            $operationName = $operation->getName();
        }

        return [
            '/service/' . $serviceName . '/operation/'
                . $operationName,
            $operation->getInputSchema()->resolveTarget()->id
                === self::UNIT_SHAPE_ID,
            $operation->getOutputSchema()->resolveTarget()->id
                === self::UNIT_SHAPE_ID
        ];
    }

    private function hasExpectedProtocolHeader(ResponseInterface $response): bool
    {
        return $response->getHeaderLine('Smithy-Protocol')
            === static::$protocolHeaderValue;
    }
}

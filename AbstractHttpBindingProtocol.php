<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\StreamInterface;
use Smithy\Core\Endpoint\Endpoint;
use Smithy\Core\Transport\{
    MessageExchangeInterface,
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;
use Smithy\Http\Message\{
    HttpMessageExchange,
    HttpTransportRequest,
    HttpTransportResponse,
    Request,
    RequestStreamInterface,
    ResponseInterface,
    Uri
};
use Smithy\Protocol\Utils\ComponentUri;

/**
 * Share HTTP binding logic for protocols that use HTTP binding traits.
 *
 * Reads the operation's HTTP binding extension for the URI / method
 * / segments, and the input schema's {@see HttpStructBindingExtension}
 * for member-level binding routing. Subclasses provide the codec and
 * protocol-specific error deserializer.
 */
abstract class AbstractHttpBindingProtocol implements ProtocolInterface
{
    protected const HEADER_CONTENT_TYPE = 'content-type';
    protected const HEADER_CONTENT_LENGTH = 'content-length';

    /**
     * @var string Default payload content type for the concrete protocol.
     */
    protected static string $defaultContentType;

    /**
     * Per-operation request-side runtime cache.
     *
     * Tuple layout:
     *   [0] HttpOperationBindingExtension
     *   [1] ?HttpStructBindingExtension input binding info
     *
     * @var array<string, array{0: HttpOperationBindingExtension, 1: ?HttpStructBindingExtension}>
     */
    private array $requestRuntimeByOperation = [];

    /**
     * Per-operation response-side runtime cache.
     *
     * Tuple layout:
     *   [0] output Schema
     *   [1] ?HttpStructBindingExtension output binding info
     *
     * @var array<string, array{0: Schema, 1: ?HttpStructBindingExtension}>
     */
    private array $responseRuntimeByOperation = [];

    /**
     * Create an HTTP binding protocol with its payload codec.
     */
    public function __construct(
        protected readonly AbstractCodec $codec,
        private readonly HttpErrorDeserializer $errorDeserializer,
        private readonly ProtocolSettings $settings
    ) {}

    /** @inheritDoc */
    public function getMessageExchange(): MessageExchangeInterface
    {
        return HttpMessageExchange::instance();
    }

    /**
     * Serialize operation input into an HTTP request.
     */
    public function serializeRequest(
        OperationInterface $operation,
        SerializableStructInterface $input
    ): TransportRequestInterface
    {
        static $opKey = null;
        static $bindingKey = null;
        $opKey ??= HttpOperationBindingExtensionProvider::$KEY
            ?? HttpOperationBindingExtensionProvider::key();
        $bindingKey ??= HttpStructBindingExtensionProvider::$KEY
            ?? HttpStructBindingExtensionProvider::key();

        $runtimeIdentity = $operation->getRuntimeIdentity();
        $runtime = $this->requestRuntimeByOperation[$runtimeIdentity] ?? null;
        if ($runtime === null) {
            $inputSchema = $operation->getInputSchema();
            /** @var HttpStructBindingExtension|null $bindingInfo */
            $bindingInfo = $inputSchema->getExtension($bindingKey);
            $runtime = [
                $operation->getSchema()->getExtension($opKey),
                $bindingInfo
            ];
            $this->requestRuntimeByOperation[$runtimeIdentity] = $runtime;
        }

        $opInfo = $runtime[0];
        $method = $opInfo->method;
        $defaultPath = $opInfo->path;
        $pathSegments = $opInfo->pathSegments;
        $pathQueryString = $opInfo->pathQueryString;
        $bindingInfo = $runtime[1];

        if ($bindingInfo !== null) {
            $serializer = new HttpBindingSerializer(
                $this->codec,
                $bindingInfo,
                $this->settings
            );
            $input->serializeMembers($serializer);
            $path = $serializer->serializePath($pathSegments, $defaultPath);
            $queryString = $serializer->serializeQueryString(
                $pathQueryString
            );
            $body = $serializer->serializeBody();
            $headers = $serializer->serializeHeaders();
            $payloadContentType = $serializer->resolveContentType();
        } else {
            $path = $defaultPath;
            $queryString = '';
            $headers = [];
            $codecSerializer = $this->codec->createSerializer();
            $input->serialize($codecSerializer, 0);
            $body = $codecSerializer->flush();
            $payloadContentType = null;
        }

        if ($body instanceof StreamInterface || $body !== '') {
            $headers[self::HEADER_CONTENT_TYPE] ??= [
                self::HEADER_CONTENT_TYPE,
                [$payloadContentType ?? static::$defaultContentType]
            ];

            if (!isset($headers[self::HEADER_CONTENT_LENGTH])) {
                if (\is_string($body)) {
                    $length = \strlen($body);
                } elseif ($body instanceof RequestStreamInterface) {
                    $length = $body->length();
                } else {
                    $length = $body->getSize();
                }

                if ($length !== null) {
                    $headers[self::HEADER_CONTENT_LENGTH] = [
                        self::HEADER_CONTENT_LENGTH,
                        [(string) $length]
                    ];
                }
            }
        }

        $uri = new ComponentUri(
            scheme: '',
            host: '',
            path: $path,
            query: $queryString
        );

        return new HttpTransportRequest(
            Request::fromNormalized(
                $method,
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
     * Deserialize an HTTP response into operation output.
     */
    public function deserializeResponse(
        OperationInterface $operation,
        TransportResponseInterface $response
    ): SerializableStructInterface
    {
        $response = HttpTransportResponse::expect($response)->response;
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 300) {
            throw $this->errorDeserializer->deserialize(
                $operation,
                $response
            );
        }

        static $bindingKey = null;
        $bindingKey ??= HttpStructBindingExtensionProvider::$KEY
            ?? HttpStructBindingExtensionProvider::key();

        $runtimeIdentity = $operation->getRuntimeIdentity();
        $runtime = $this->responseRuntimeByOperation[$runtimeIdentity] ?? null;
        if ($runtime === null) {
            $outputSchema = $operation->getOutputSchema();
            $runtime = [
                $outputSchema,
                $outputSchema->getExtension($bindingKey)
            ];
            $this->responseRuntimeByOperation[$runtimeIdentity] = $runtime;
        }

        $outputSchema = $runtime[0];
        $members = $this->codec->createMembersBuffer($outputSchema);
        $memberCount = \count($members);

        if ($memberCount) {
            /** @var HttpStructBindingExtension $bindingInfo */
            $bindingInfo = $runtime[1];

            $deserializer = new HttpBindingDeserializer(
                $this->codec,
                $bindingInfo,
                $response instanceof ResponseInterface
                    ? $response->getRawHeaders()
                    : self::normalizeResponseHeaders($response),
                $response->getBody(),
                $statusCode
            );

            $deserializer->deserialize($outputSchema, $members);
        }

        return $operation->createOutput($members);
    }

    /**
     * Normalize headers from a PSR-7 response that is not a Smithy response.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function normalizeResponseHeaders(
        PsrResponseInterface $response
    ): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $name = (string) $name;
            $headers[\strtolower($name)] = [$name, \array_values($values)];
        }

        return $headers;
    }
}

<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol\Aws;

use Psr\Http\Message\ResponseInterface;
use Smithy\Core\Endpoint\Endpoint;
use Smithy\Core\Transport\{
    MessageExchangeInterface,
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Codec\Json\{
    JsonCodec,
    JsonCodecSettings
};
use Smithy\Core\Serde\{
    SerializableStructInterface,
    TimestampFormat
};
use Smithy\Core\Shape\OperationInterface;
use Smithy\Http\Message\{
    HttpMessageExchange,
    HttpTransportRequest,
    HttpTransportResponse,
    Request,
    Uri
};
use Smithy\Protocol\Exception\ProtocolException;
use Smithy\Protocol\ProtocolInterface;
use Smithy\Protocol\Utils\ComponentUri;

/**
 * Implement shared AWS JSON RPC request and response behavior.
 */
abstract class AbstractAwsJsonProtocol implements ProtocolInterface
{
    protected const HEADER_CONTENT_LENGTH = 'content-length';
    protected const HEADER_CONTENT_TYPE = 'content-type';
    protected const HEADER_X_AMZ_TARGET = 'x-amz-target';

    protected static string $contentType;

    protected readonly JsonCodec $codec;
    private readonly AwsJsonErrorDeserializer $errorDeserializer;

    public function __construct(
        private readonly ?string $targetPrefix = null
    )
    {
        if ($targetPrefix === '') {
            throw new \InvalidArgumentException(
                'AWS JSON target prefix must not be empty.'
            );
        }

        $this->codec = new JsonCodec(new JsonCodecSettings(
            defaultTimestampFormat: TimestampFormat::EpochSeconds,
            honorJsonName: false
        ));
        $this->errorDeserializer = new AwsJsonErrorDeserializer($this->codec);
    }

    /** @inheritDoc */
    public function getMessageExchange(): MessageExchangeInterface
    {
        return HttpMessageExchange::instance();
    }

    public function serializeRequest(
        OperationInterface $operation,
        SerializableStructInterface $input
    ): TransportRequestInterface
    {
        $serializer = $this->codec->acquireSerializer();
        try {
            $input->serialize($serializer, 0);
            $body = $serializer->flush();
        } finally {
            $this->codec->releaseSerializer($serializer);
        }

        if ($body === '' || $body === 'null') {
            $body = '{}';
        }

        $headers = [
            self::HEADER_CONTENT_TYPE => [
                'Content-Type',
                [static::$contentType]
            ],
            self::HEADER_CONTENT_LENGTH => [
                'Content-Length',
                [(string) \strlen($body)]
            ],
            self::HEADER_X_AMZ_TARGET => [
                'X-Amz-Target',
                [$this->resolveRequestTarget($operation)]
            ]
        ];

        return new HttpTransportRequest(
            Request::fromNormalized(
                'POST',
                new ComponentUri(scheme: '', host: '', path: '/'),
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

    public function deserializeResponse(
        OperationInterface $operation,
        TransportResponseInterface $response
    ): SerializableStructInterface
    {
        $response = HttpTransportResponse::expect($response)->response;
        if ($response->getStatusCode() >= 300) {
            throw $this->errorDeserializer->deserialize(
                $operation,
                $response
            );
        }

        $schema = $operation->getOutputSchema()->resolveTarget();
        $members = $this->codec->createMembersBuffer($schema);
        if ($members !== [] && self::hasBody($response)) {
            $this->codec->createDeserializer($response->getBody())
                ->deserialize($schema, $members);
        }

        return $operation->createOutput($members);
    }

    private function resolveRequestTarget(
        OperationInterface $operation
    ): string
    {
        $target = $operation->getRequestTarget();
        if ($target !== null && $target !== '') {
            return $target;
        }

        $prefix = $this->targetPrefix;
        if ($prefix === null) {
            $serviceId = $operation->getServiceId();
            if ($serviceId === null || $serviceId === '') {
                throw new ProtocolException(
                    'AWS JSON operations require a request target or '
                        . 'service shape ID.'
                );
            }

            $separator = \strrpos($serviceId, '#');
            $prefix = $separator === false
                ? $serviceId
                : \substr($serviceId, $separator + 1);
        }

        return $prefix . '.' . $operation->getName();
    }

    private static function hasBody(
        ResponseInterface $response
    ): bool
    {
        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength !== '') {
            return (int) $contentLength !== 0;
        }

        return $response->getBody()->getSize() !== 0;
    }
}

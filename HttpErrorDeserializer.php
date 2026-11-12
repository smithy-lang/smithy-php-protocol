<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol;

use Psr\Http\Message\ResponseInterface;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Exception\ApiException;
use Smithy\Core\Exception\ErrorFault;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Shape\Exception\DocumentException;
use Smithy\Core\Shape\OperationInterface;
use Smithy\Http\Message\StringRequestStream;

/**
 * Deserialize modeled errors for HTTP protocols.
 *
 * Concrete protocols extract their envelope fields in {@see parseError()}
 * and may override member deserialization or unmodeled error construction.
 */
abstract class HttpErrorDeserializer
{
    /**
     * Create an error deserializer for a codec.
     */
    public function __construct(
        protected readonly AbstractCodec $codec
    ) {}

    /**
     * Deserialize a modeled error or create the protocol's generic fallback.
     */
    final public function deserialize(
        OperationInterface $operation,
        ResponseInterface $response
    ): \Throwable
    {
        try {
            [
                'discriminator' => $discriminator,
                'message' => $message,
                'body' => $body,
                'requestId' => $requestId
            ] = $this->parseError($response);
        } catch (\Throwable $e) {
            return $this->createUnmodeledError(
                $operation,
                $response,
                null,
                $e->getMessage(),
                null,
                $e
            );
        }

        $errorSchema = $discriminator !== null && $discriminator !== ''
            ? $operation->resolveErrorSchema($discriminator)
            : null;
        if ($errorSchema === null) {
            return $this->createUnmodeledError(
                $operation,
                $response,
                $discriminator,
                $message,
                $requestId
            );
        }

        try {
            $schema = $errorSchema->resolveTarget();
            $errorClass = $schema->targetClass;
            if ($errorClass !== null
                && !\method_exists($errorClass, 'fromMembers')
            ) {
                return new $errorClass(
                    $message,
                    $this->resolveFault($response)
                );
            }

            $members = $this->codec->createMembersBuffer($schema);
            if ($members !== []) {
                $this->deserializeMembers(
                    $schema,
                    $members,
                    $response,
                    $body
                );
            }

            return $errorClass !== null
                ? $errorClass::fromMembers($members)
                : new DocumentException(
                    $schema,
                    $members,
                    $message,
                    $this->resolveFault($response)
                );
        } catch (\Throwable $e) {
            return $this->createUnmodeledError(
                $operation,
                $response,
                $discriminator,
                $message,
                $requestId,
                $e
            );
        }
    }

    /**
     * Extract the protocol error discriminator and envelope metadata.
     *
     * @return array{
     *     discriminator: ?string,
     *     message: string,
     *     body: string,
     *     requestId: ?string
     * }
     */
    abstract protected function parseError(
        ResponseInterface $response
    ): array;

    /**
     * Deserialize modeled members from the parsed error payload.
     *
     * @param array<int, mixed> $members Positional member values.
     *
     * @return void
     */
    protected function deserializeMembers(
        Schema $schema,
        array &$members,
        ResponseInterface $response,
        string $body
    ): void
    {
        if ($body === '') {
            return;
        }

        $this->codec->createDeserializer(
            new StringRequestStream($body)
        )->deserialize($schema, $members);
    }

    /**
     * Create the generic exception used for unmodeled or malformed errors.
     */
    protected function createUnmodeledError(
        OperationInterface $operation,
        ResponseInterface $response,
        ?string $discriminator,
        string $message,
        ?string $requestId,
        ?\Throwable $previous = null
    ): \Throwable
    {
        return $this->createUnmodeledErrorWithClass(
            $operation->getServiceExceptionClass(),
            $response,
            $discriminator,
            $message,
            $previous
        );
    }

    /**
     * @param class-string<ApiException> $errorClass
     */
    private function createUnmodeledErrorWithClass(
        string $errorClass,
        ResponseInterface $response,
        ?string $discriminator,
        string $message,
        ?\Throwable $previous
    ): ApiException
    {
        $statusCode = $response->getStatusCode();
        if ($message === '') {
            $message = $discriminator !== null && $discriminator !== ''
                ? "HTTP {$statusCode}: {$discriminator}"
                : "HTTP {$statusCode}";
        }

        return new $errorClass(
            $message,
            $this->resolveFault($response),
            $previous
        );
    }

    /**
     * Resolve the Smithy fault classification from the HTTP status.
     */
    protected function resolveFault(
        ResponseInterface $response
    ): ErrorFault
    {
        return $response->getStatusCode() >= 500
            ? ErrorFault::Server
            : ErrorFault::Client;
    }
}

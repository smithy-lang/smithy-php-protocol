<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol\RpcV2;

use Psr\Http\Message\ResponseInterface;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Shape\ShapeType;
use Smithy\Http\Message\StringRequestStream;
use Smithy\Protocol\Exception\ProtocolException;
use Smithy\Protocol\HttpErrorDeserializer;

/**
 * Deserialize Smithy RPC v2 error responses.
 */
final class RpcV2ErrorDeserializer extends HttpErrorDeserializer
{
    private static ?Schema $envelopeSchema = null;

    /**
     * Create an RPC v2 error deserializer.
     */
    public function __construct(
        AbstractCodec $codec,
        private readonly string $protocolHeaderValue
    )
    {
        parent::__construct($codec);
    }

    /**
     * Parse the RPC v2 error envelope.
     *
     * @return array{
     *     discriminator: ?string,
     *     message: string,
     *     body: string,
     *     requestId: ?string
     * }
     */
    protected function parseError(ResponseInterface $response): array
    {
        if ($response->getHeaderLine('Smithy-Protocol')
            !== $this->protocolHeaderValue
        ) {
            $statusCode = $response->getStatusCode();

            throw new ProtocolException(
                'Malformed ' . $this->protocolHeaderValue
                . " error response (HTTP {$statusCode})."
            );
        }

        $body = $response->getHeaderLine('Content-Length') === '0'
            ? ''
            : $response->getBody()->getContents();
        if ($body === '') {
            return [
                'discriminator' => null,
                'message' => '',
                'body' => '',
                'requestId' => null
            ];
        }

        $members = [null, null];
        $this->codec->createDeserializer(
            new StringRequestStream($body)
        )->deserialize(self::envelopeSchema(), $members);

        return [
            'discriminator' => \is_string($members[0])
                ? $members[0]
                : null,
            'message' => \is_string($members[1]) ? $members[1] : '',
            'body' => $body,
            'requestId' => null
        ];
    }

    private static function envelopeSchema(): Schema
    {
        return self::$envelopeSchema ??= new Schema(
            id: 'smithy.protocols#rpcv2ErrorEnvelope',
            type: ShapeType::Structure,
            members: [
                new Schema(
                    id: 'smithy.protocols#rpcv2ErrorEnvelope$__type',
                    type: ShapeType::String_,
                    memberName: '__type',
                    memberIndex: 0
                ),
                new Schema(
                    id: 'smithy.protocols#rpcv2ErrorEnvelope$message',
                    type: ShapeType::String_,
                    memberName: 'message',
                    memberIndex: 1
                )
            ]
        );
    }
}

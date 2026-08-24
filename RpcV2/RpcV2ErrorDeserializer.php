<?php

declare(strict_types=1);

namespace Smithy\Protocol\RpcV2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Shape\ShapeType;
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
     * Validate RPC v2 error response metadata.
     *
     * @return void
     */
    protected function validateResponse(ResponseInterface $response): void
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
    }

    /**
     * Parse the RPC v2 error envelope.
     *
     * @return array{
     *     discriminator: ?string,
     *     message: string,
     *     deserializer: ShapeDeserializerInterface,
     *     requestId: ?string
     * }
     */
    protected function parseError(StreamInterface $body): array
    {
        $members = [null, null];
        $deserializer = $this->codec->createDeserializer($body);
        $deserializer->deserialize(self::envelopeSchema(), $members);

        return [
            'discriminator' => \is_string($members[0])
                ? $members[0]
                : null,
            'message' => \is_string($members[1]) ? $members[1] : '',
            'deserializer' => $deserializer,
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

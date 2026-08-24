<?php

declare(strict_types=1);

namespace Smithy\Protocol\RpcV2;

use Smithy\Codec\Cbor\CborCodec;
use Smithy\Codec\Cbor\CborCodecSettings;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Smithy `rpcv2Cbor` protocol.
 *
 * Uses the shared RPC v2 request/response framing from
 * {@see AbstractRpcV2Protocol} and plugs in the CBOR codec plus the wire
 * identifiers required by the public Smithy specification.
 */
final class RpcV2CborProtocol extends AbstractRpcV2Protocol
{
    /**
     * @var string Smithy-Protocol header value for RPC v2 CBOR.
     */
    protected static string $protocolHeaderValue = 'rpc-v2-cbor';
    /**
     * @var string Buffered RPC v2 CBOR media type.
     */
    protected static string $mediaType = 'application/cbor';

    /**
     * Create an RPC v2 CBOR protocol.
     */
    public function __construct()
    {
        $codec = new CborCodec(new CborCodecSettings(
            defaultTimestampFormat: TimestampFormat::EpochSeconds,
            honorTimestampFormatTrait: false
        ));

        parent::__construct(
            $codec,
            new RpcV2ErrorDeserializer($codec, static::$protocolHeaderValue)
        );
    }
}

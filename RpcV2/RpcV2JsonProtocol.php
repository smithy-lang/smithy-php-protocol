<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol\RpcV2;

use Smithy\Codec\Json\{
    JsonCodec,
    JsonCodecSettings
};
use Smithy\Core\Serde\TimestampFormat;

/**
 * Smithy `rpcv2Json` protocol.
 */
final class RpcV2JsonProtocol extends AbstractRpcV2Protocol
{
    public const ID = 'smithy.protocols#rpcv2Json';

    /**
     * @var string Smithy-Protocol header value for RPC v2 JSON.
     */
    protected static string $protocolHeaderValue = 'rpc-v2-json';

    /**
     * @var string Buffered RPC v2 JSON media type.
     */
    protected static string $mediaType = 'application/json';

    /**
     * Create an RPC v2 JSON protocol.
     */
    public function __construct()
    {
        $codec = new JsonCodec(new JsonCodecSettings(
            defaultTimestampFormat: TimestampFormat::EpochSeconds,
            honorJsonName: false,
            honorTimestampFormatTrait: false,
            useStringForArbitraryPrecision: true
        ));

        parent::__construct(
            $codec,
            new RpcV2ErrorDeserializer($codec, static::$protocolHeaderValue)
        );
    }

    /**
     * Return the Smithy protocol trait shape ID.
     */
    public function getId(): string
    {
        return self::ID;
    }
}

<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol\Utils;

use Psr\Http\Message\ResponseInterface;

/**
 * Check HTTP response bodies.
 *
 * @internal Shared by the protocol implementations.
 */
final class ResponseBody
{
    /**
     * Return whether the response has body bytes.
     *
     * `Content-Length` decides when present. Otherwise an unknown stream size
     * counts as content.
     */
    public static function hasContent(ResponseInterface $response): bool
    {
        $contentLength = $response->getHeaderLine('Content-Length');
        if ($contentLength !== '') {
            return (int) $contentLength !== 0;
        }

        return $response->getBody()->getSize() !== 0;
    }
}

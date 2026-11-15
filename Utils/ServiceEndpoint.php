<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol\Utils;

use Smithy\Core\Endpoint\Endpoint;
use Smithy\Core\Transport\TransportRequestInterface;
use Smithy\Http\Message\{
    HttpTransportRequest,
    Uri
};

/**
 * Apply a resolved endpoint to a protocol-built HTTP request.
 *
 * @internal Shared by the protocol implementations.
 */
final class ServiceEndpoint
{
    private const MAX_CACHED_URLS = 64;

    /**
     * Parsed endpoint parts keyed by endpoint URL.
     *
     * Each entry is `[scheme, host, port, basePath]`. `basePath` has no
     * trailing slash and is empty for a root path.
     *
     * @var array<string, array{0: string, 1: string, 2: ?int, 3: string}>
     */
    private static array $parsed = [];

    /**
     * Join the endpoint URL with the request's path and query.
     *
     * The endpoint URL is validated once per distinct URL. The request path
     * and query were already encoded by the protocol and are not filtered
     * again. The endpoint query and fragment are dropped.
     */
    public static function apply(
        TransportRequestInterface $request,
        Endpoint $endpoint
    ): HttpTransportRequest
    {
        $request = HttpTransportRequest::expect($request);
        $httpRequest = $request->request;
        $url = $endpoint->url;
        $parts = self::$parsed[$url] ?? self::parse($url);
        $requestUri = $httpRequest->getUri();

        return $request
            ->withRequest($httpRequest->withUri(new ComponentUri(
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[3] . $requestUri->getPath(),
                $requestUri->getQuery()
            )))
            ->withEndpointHeaders($endpoint);
    }

    /**
     * Parse and cache an endpoint URL.
     *
     * @return array{0: string, 1: string, 2: ?int, 3: string}
     */
    private static function parse(string $url): array
    {
        // shortcut: the cache is cleared when full. Upgrade to LRU if
        // per-request endpoint URLs churn past the cap.
        if (\count(self::$parsed) >= self::MAX_CACHED_URLS) {
            self::$parsed = [];
        }

        $uri = new Uri($url);
        $path = $uri->getPath();

        return self::$parsed[$url] = [
            $uri->getScheme(),
            $uri->getHost(),
            $uri->getPort(),
            $path !== '' && $path !== '/' ? \rtrim($path, '/') : ''
        ];
    }
}

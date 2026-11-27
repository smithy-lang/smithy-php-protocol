<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol;

/**
 * Carry precomputed HTTP binding metadata for an operation schema.
 *
 * @internal Runtime metadata attached to operation schemas.
 */
final class HttpOperationBindingExtension
{
    public function __construct(
        /**
         * HTTP method from the `@http` trait.
         */
        public readonly string $method = 'POST',
        /**
         * URI path from the `@http` trait with label placeholders intact.
         */
        public readonly string $path = '/',
        /**
         * Precomputed path segments for O(1) URI resolution.
         *
         * Each segment is a literal `[string $literal, null, false]` or a
         * label `[null, int $labelIndex, bool $greedy]`. Use null for fully
         * literal paths.
         *
         * @var list<array{string, null, false}|array{null, int, bool}>|null
         */
        public readonly ?array $pathSegments = null,
        /**
         * Precomputed static query string from the `@http` URI.
         */
        public readonly ?string $pathQueryString = null
    ) {}
}

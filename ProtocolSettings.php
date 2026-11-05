<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol;

/**
 * Runtime settings shared by HTTP binding protocols.
 */
final readonly class ProtocolSettings
{
    public function __construct(
        public HttpPathLabelPolicy $httpPathLabelPolicy =
            HttpPathLabelPolicy::RejectAmbiguousSegments
    ) {}
}

<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol;

/**
 * Controls how ambiguous dot and empty segments in HTTP URI labels are handled.
 */
enum HttpPathLabelPolicy
{
    /**
     * Reject ambiguous path segments before constructing the request URI.
     */
    case RejectAmbiguousSegments;

    /**
     * Preserve ambiguous segments in greedy labels.
     *
     * Non-greedy labels remain subject to strict validation.
     */
    case PreserveGreedySegments;
}

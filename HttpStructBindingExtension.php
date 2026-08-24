<?php

declare(strict_types=1);

namespace Smithy\Protocol;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Carry precomputed HTTP binding metadata for a structure or union schema.
 *
 * @internal Runtime metadata attached to HTTP-bound structure schemas.
 */
final class HttpStructBindingExtension
{
    public const ROUTE_HEADER = 0;
    public const ROUTE_QUERY = 1;
    public const ROUTE_LABEL = 2;
    public const ROUTE_PAYLOAD = 3;
    public const ROUTE_BODY_MEMBER = 4;
    public const ROUTE_PREFIX_HEADERS = 5;
    public const ROUTE_QUERY_PARAMS = 6;
    public const ROUTE_RESPONSE_CODE = 7;

    /**
     * @internal
     */
    public function __construct(
        /**
         * Flat routing table: memberIndex => ROUTE_* constant.
         *
         * @var array<int, int>
         */
        public readonly array $memberRoutes = [],
        /**
         * Fused per-member write table.
         *
         * Entries are `[route, target]`, with an optional third slot set to
         * `true` for header-bound string members carrying `@mediaType`.
         * The target value is the lowercase header name, URL-encoded query
         * name, lowercase prefix string, or null for untargeted routes.
         *
         * @var array<int, array{0: int, 1: ?string, 2?: true}>
         */
        public readonly array $writeInfo = [],
        /** @var array<int, string> memberIndex => lowercase header name */
        public readonly array $headerMembers = [],
        /**
         * URL-encoded `@httpQuery` member names, keyed by member index.
         *
         * @var array<int, string>
         */
        public readonly array $queryMembers = [],
        /** @var array<int, string> memberIndex => lowercase prefix string */
        public readonly array $prefixHeaderMembers = [],
        /**
         * Raw `@httpQuery` wire names used by `@httpQueryParams`.
         *
         * @var array<string, true>
         */
        public readonly array $httpQueryNames = [],
        /** @var list<int> Body-eligible member indices. */
        public readonly array $bodyMemberIndices = [],
        /**
         * Cached filtered body schema.
         */
        public readonly ?Schema $bodySchema = null,
        /** Schema for the `@httpPayload` member. */
        public readonly ?Schema $payloadSchema = null,
        /** `@mediaType` trait value from the `@httpPayload` member. */
        public readonly ?string $payloadMediaType = null,
        /**
         * Request timestamp format kind, keyed by member index.
         *
         * Values use the backed integer from {@see TimestampFormat}.
         *
         * @var array<int, int>
         */
        public readonly array $requestTimestampKinds = [],
        /**
         * memberIndex of the input member carrying `@idempotencyToken`, or
         * null. The protocol layer reads this and, when the caller did not
         * supply a value, generates a UUID-v4 and pushes it into the
         * matching binding bucket before invoking `serializeMembers()`.
         */
        public readonly ?int $idempotencyTokenMemberIndex = null,

        /**
         * Response header lookup entries, keyed by lowercase header name.
         *
         * Scalar entries are `[0, memberIndex, ShapeType-int]`, with an
         * optional target class in slot 3 for enum and intEnum members.
         * List entries are `[1, memberIndex, Schema, listItemType-int]`.
         *
         * @var array<string, array<int, mixed>>
         */
        public readonly array $responseHeaders = [],
        /**
         * memberIndex => [lowerPrefix, prefixLen] for `@httpPrefixHeaders`.
         *
         * @var array<int, array{string, int}>
         */
        public readonly array $prefixHeaderDeserMembers = [],
        /** memberIndex of the `@httpResponseCode` member. */
        public readonly ?int $responseCodeMemberIndex = null,
        /** memberIndex of the `@httpPayload` member. */
        public readonly ?int $payloadMemberIndex = null,

        /**
         * Scalar response-header timestamp formats, keyed by member index.
         *
         * Values are populated for timestamp-typed bindings. The Smithy wire
         * default for `@httpHeader` is HttpDate.
         *
         * @var array<int, TimestampFormat>
         */
        public readonly array $scalarTimestampFormats = [],
        /**
         * memberIndex => timestamp-format kind for list-typed header / query
         * members whose inner element is a timestamp.
         *
         * @var array<int, int>
         */
        public readonly array $listTimestampKinds = []
    ) {}
}

<?php

declare(strict_types=1);

namespace Smithy\Protocol;

use Psr\Http\Message\StreamInterface;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\DocumentInterface;

/**
 * Serialize structure members into HTTP binding locations.
 *
 * @internal Runtime serializer for HTTP binding protocols.
 */
final class HttpBindingSerializer implements ShapeSerializerInterface
{
    /**
     * Headers in PSR-7 internal format.
     *
     * @var array<string, array{string, list<string>}>
     */
    private array $headers = [];
    /**
     * Encoded dynamic query string.
     */
    private string $queryString = '';
    /**
     * Formatted URI label values, keyed by member index.
     *
     * @var array<int, string>
     */
    private array $labels = [];
    /**
     * Captured `@httpPayload` value.
     *
     * @var mixed
     */
    private mixed $payload = null;
    private bool $hasPayload = false;
    private ?ShapeSerializerInterface $codecSerializer = null;
    /**
     * Fused per-member write table: `memberIndex => [route, target]`.
     *
     * `target` is the precomputed lowercase header name for ROUTE_HEADER,
     * the URL-encoded query name for ROUTE_QUERY, the lowercase prefix
     * string for ROUTE_PREFIX_HEADERS, or null. Header-bound string members
     * carrying `@mediaType` append a third slot set to `true`, so the string
     * hot path can base64-encode without a second metadata probe.
     *
     * @var array<int, array{0: int, 1: ?string, 2?: true}>
     */
    private readonly array $writeInfo;
    /**
     * memberIndex => precomputed timestamp-format kind for request-side
     * header/label/query timestamps. Values match {@see TimestampFormat}'s
     * backed ints: 0 = EpochSeconds, 1 = DateTime, 2 = HttpDate.
     *
     * @var array<int, int>
     */
    private readonly array $requestTimestampKinds;
    /** @var array<int, int> */
    private readonly array $listTimestampKinds;
    /**
     * Raw `@httpQuery` wire names used by `@httpQueryParams` to detect
     * per-key conflicts against modeled query members. Keyed by raw
     * unencoded name to match user-supplied keys directly.
     *
     * @var array<string, true>
     */
    private readonly array $httpQueryNames;
    /** @var array<string, true> */
    private readonly array $headerWireNames;

    /**
     * Create an HTTP binding serializer.
     */
    public function __construct(
        private readonly AbstractCodec $codec,
        private readonly HttpStructBindingExtension $bindingInfo
    )
    {
        $this->writeInfo = $bindingInfo->writeInfo;
        $this->requestTimestampKinds = $bindingInfo->requestTimestampKinds;
        $this->listTimestampKinds = $bindingInfo->listTimestampKinds;
        $this->httpQueryNames = $bindingInfo->httpQueryNames;
        $this->headerWireNames = $bindingInfo->headerWireNames;

        if ($bindingInfo->bodyMemberIndices !== []) {
            $this->codecSerializer = $this->codec->createSerializer();
            $this->codecSerializer->beginStruct($bindingInfo->bodySchema, 0);
        }
    }

    /** @inheritDoc */
    public function serializeBoolean(Schema $schema, bool $value, int $idx): void
    {
        $strValue = $value ? 'true' : 'false';
        $w = $this->writeInfo[$idx];
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [$w[1], [$strValue]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], $strValue);

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = $strValue;

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeBoolean($schema, $value, $idx);

                return;
        }
    }

    /** @inheritDoc */
    public function serializeInteger(Schema $schema, int $value, int $idx): void
    {
        $w = $this->writeInfo[$idx];
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [$w[1], [(string) $value]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], (string) $value);

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = (string) $value;

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeInteger($schema, $value, $idx);

                return;
        }
    }

    /** @inheritDoc */
    public function serializeFloat(Schema $schema, float $value, int $idx): void
    {
        $w = $this->writeInfo[$idx];
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [$w[1], [self::formatFloat($value)]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], self::formatFloat($value));

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = self::formatFloat($value);

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeFloat($schema, $value, $idx);

                return;
        }
    }

    /** @inheritDoc */
    public function serializeBigInteger(
        Schema $schema,
        BigInteger $value,
        int $idx
    ): void
    {
        // Big numbers travel as their validated decimal string in HTTP
        // bindings, and as number tokens in body routes.
        $w = $this->writeInfo[$idx];
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [$w[1], [$value->value]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], $value->value);

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = $value->value;

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeBigInteger(
                    $schema,
                    $value,
                    $idx
                );

                return;
        }
    }

    /** @inheritDoc */
    public function serializeBigDecimal(
        Schema $schema,
        BigDecimal $value,
        int $idx
    ): void
    {
        $w = $this->writeInfo[$idx];
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [$w[1], [$value->value]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], $value->value);

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = $value->value;

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeBigDecimal(
                    $schema,
                    $value,
                    $idx
                );

                return;
        }
    }

    /** @inheritDoc */
    public function serializeString(Schema $schema, string $value, int $idx): void
    {
        $w = $this->writeInfo[$idx];
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                if (isset($w[2])) {
                    $value = \base64_encode($value);
                }
                $this->headers[$w[1]] = [$w[1], [$value]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], $value);

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = $value;

                return;
            case HttpStructBindingExtension::ROUTE_PAYLOAD:
                $this->payload = $value;
                $this->hasPayload = true;

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeString($schema, $value, $idx);

                return;
        }
    }

    /** @inheritDoc */
    public function serializeBlob(
        Schema $schema,
        string|StreamInterface $value,
        int $idx
    ): void
    {
        $w = $this->writeInfo[$idx];
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                if (!$value instanceof StreamInterface) {
                    $this->headers[$w[1]] = [$w[1], [\base64_encode($value)]];

                    return;
                }

                break;
            case HttpStructBindingExtension::ROUTE_PAYLOAD:
                $this->payload = $value;
                $this->hasPayload = true;

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                if (!$value instanceof StreamInterface) {
                    $this->codecSerializer->serializeBlob(
                        $schema,
                        $value,
                        $idx
                    );

                    return;
                }

                break;
        }

        throw new \InvalidArgumentException(
            'Only @httpPayload blob members may be streams.'
        );
    }

    /** @inheritDoc */
    public function serializeTimestamp(
        Schema $schema,
        float $value,
        int $idx
    ): void
    {
        $w = $this->writeInfo[$idx];
        if ($w[0] === HttpStructBindingExtension::ROUTE_BODY_MEMBER) {
            $this->codecSerializer->serializeTimestamp(
                $schema,
                $value,
                $idx
            );

            return;
        }

        switch ($this->requestTimestampKinds[$idx]) {
            case TimestampFormat::DateTime->value:
                $formatted = \gmdate(
                    TimestampFormat::FMT_DATE_TIME,
                    (int) $value
                );
                break;
            case TimestampFormat::HttpDate->value:
                $formatted = \gmdate(
                    TimestampFormat::FMT_HTTP_DATE,
                    (int) $value
                );
                break;
            default: // TimestampFormat::EpochSeconds
                $formatted = (string) ($value + 0);
                break;
        }

        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [$w[1], [$formatted]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], $formatted);

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = $formatted;

                return;
        }
    }

    /** @inheritDoc */
    public function serializeList(Schema $schema, array $list, int $idx): void
    {
        $w = $this->writeInfo[$idx];
        $memberType = $schema->getListMember()?->type
            ?? ShapeType::Document->value;
        switch ($w[0]) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [
                    $w[1],
                    [$this->formatListHeader($list, $idx, $memberType)]
                ];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->serializeListQuery($list, $w[1], $idx, $memberType);

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeList($schema, $list, $idx);

                return;
        }
    }

    /** @inheritDoc */
    public function serializeMap(Schema $schema, array $map, int $idx): void
    {
        $w = $this->writeInfo[$idx];
        match ($w[0]) {
            HttpStructBindingExtension::ROUTE_BODY_MEMBER =>
                $this->codecSerializer->serializeMap($schema, $map, $idx),
            HttpStructBindingExtension::ROUTE_PREFIX_HEADERS =>
                $this->serializePrefixHeaders($map, $w[1]),
            HttpStructBindingExtension::ROUTE_QUERY_PARAMS =>
                $this->serializeQueryParams($map),
            default => null
        };
    }

    /** @inheritDoc */
    public function serializeStruct(
        Schema $schema,
        SerializableStructInterface $struct,
        int $idx
    ): void
    {
        $w = $this->writeInfo[$idx];
        if ($w[0] === HttpStructBindingExtension::ROUTE_BODY_MEMBER) {
            $this->codecSerializer->serializeStruct($schema, $struct, $idx);

            return;
        }

        if ($w[0] === HttpStructBindingExtension::ROUTE_PAYLOAD) {
            $this->payload = $struct;
            $this->hasPayload = true;
        }
    }

    /**
     * Forward to the codec's open codec frame so callers that hold an
     * `HttpBindingSerializer` reference (e.g. the document serialization
     * path) can open a nested struct in the body without reaching past the
     * binding layer.
     *
     * @return void
     */
    public function beginStruct(Schema $schema, int $idx): void
    {
        $this->codecSerializer?->beginStruct($schema, $idx);
    }

    /**
     * Forward to the codec's matching `endStruct` for the frame opened
     * via {@see beginStruct}.
     *
     * @return void
     */
    public function endStruct(): void
    {
        $this->codecSerializer?->endStruct();
    }

    /** @inheritDoc */
    public function serializeUnion(Schema $schema, mixed $union, int $idx): void
    {
        $w = $this->writeInfo[$idx];
        if ($w[0] === HttpStructBindingExtension::ROUTE_BODY_MEMBER) {
            $this->codecSerializer->serializeUnion($schema, $union, $idx);

            return;
        }

        if ($w[0] === HttpStructBindingExtension::ROUTE_PAYLOAD) {
            $this->payload = $union;
            $this->hasPayload = true;
        }
    }

    /** @inheritDoc */
    public function serializeDocument(
        Schema $schema,
        ?DocumentInterface $value,
        int $idx
    ): void
    {
        $w = $this->writeInfo[$idx];
        if ($w[0] === HttpStructBindingExtension::ROUTE_BODY_MEMBER) {
            $this->codecSerializer->serializeDocument($schema, $value, $idx);

            return;
        }

        if ($w[0] === HttpStructBindingExtension::ROUTE_PAYLOAD) {
            $this->payload = $value;
            $this->hasPayload = true;
        }
    }

    /**
     * Build the resolved path string from path segments and captured labels.
     *
     * @param list<array{?string, ?int, bool}>|null $segments Precomputed
     *     path segments.
     * @param string $defaultPath Fallback path if no segments or no labels
     *
     * @return string The resolved path
     */
    public function serializePath(?array $segments, string $defaultPath): string
    {
        if ($segments === null || $this->labels === []) {

            return $defaultPath;
        }

        $path = '';
        $labels = $this->labels;
        // Indexed access ($seg[0]/$seg[1]/$seg[2]) consistently beats
        // list-destructure ([$literal, $labelIndex, $greedy] = $seg) under
        // PHP's tracing JIT. The destructure forces an `OFFSET_GET` per
        // element plus three local-var assignments per iteration. Direct
        // indexing emits a single `OFFSET_GET` per access, and the JIT
        // specializes the typed-int subscript better. Profiled on the
        // PutObject path (4 segments) at ~10ns saved per request.
        foreach ($segments as $seg) {
            if ($seg[0] !== null) {
                $path .= $seg[0];
                continue;
            }

            if (!isset($labels[$seg[1]])) {
                throw new \LogicException(
                    "Missing HTTP label value for member index {$seg[1]}."
                );
            }

            $value = $labels[$seg[1]];
            if ($seg[2]) {
                $value = self::encodeGreedyLabel($value);
            } else {
                $value = \rawurlencode($value);
            }
            $path .= $value;
        }

        return $path;
    }

    /**
     * Append an encoded query name and raw value.
     *
     * @return void
     */
    public function writeQueryPair(string $name, string $value): void
    {
        $fragment = $name . '=' . \rawurlencode($value);
        if ($this->queryString === '') {
            $this->queryString = $fragment;

            return;
        }

        $this->queryString .= '&' . $fragment;
    }

    /**
     * Build the query string from dynamic and static query fragments.
     */
    public function serializeQueryString(?string $staticQuery = null): string
    {
        if ($this->queryString === '') {

            return $staticQuery ?? '';
        }

        if ($staticQuery === null || $staticQuery === '') {

            return $this->queryString;
        }

        return $this->queryString . '&' . $staticQuery;
    }

    /**
     * Finalize and return the request body bytes or payload stream.
     */
    public function serializeBody(): string|StreamInterface
    {
        if ($this->hasPayload) {

            return $this->buildPayload($this->payload);
        }

        $payloadSchema = $this->bindingInfo->payloadSchema;
        if ($payloadSchema?->type === ShapeType::Union->value) {
            return '';
        }

        if ($payloadSchema?->type === ShapeType::Structure->value) {
            $serializer = $this->codec->createSerializer();
            $serializer->beginStruct($payloadSchema, 0);
            $serializer->endStruct();

            return $serializer->flush();
        }

        if ($this->codecSerializer !== null) {
            $this->codecSerializer->endStruct();

            return $this->codecSerializer->flush();
        }

        return '';
    }

    /**
     * Payload-derived content-type, or null when the protocol default applies.
     *
     * Priority: `@mediaType`, then payload type, then null.
     */
    public function resolveContentType(): ?string
    {
        $payloadSchema = $this->bindingInfo->payloadSchema;

        if ($payloadSchema === null) {

            return null;
        }

        if ($this->bindingInfo->payloadMediaType) {

            return $this->bindingInfo->payloadMediaType;
        }

        if (!$this->hasPayload
            && $payloadSchema->type !== ShapeType::Structure->value
            && $payloadSchema->type !== ShapeType::Union->value
        ) {

            return null;
        }

        return match ($payloadSchema->type) {
            ShapeType::String_->value,
            ShapeType::Enum->value => 'text/plain',
            ShapeType::Blob->value => 'application/octet-stream',
            default => null,
        };
    }

    /**
     * Get the final headers array (PSR-7 internal format).
     *
     * @return array<string, array{string, list<string>}>
     */
    public function serializeHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Write an autofilled `@idempotencyToken` value into the binding bucket
     * matching the member's route. Called by the protocol layer when the
     * caller did not supply a value. Skips the `@mediaType` base64 detour
     * that `serializeString` performs because a token-bound string with
     * `@mediaType` is invalid Smithy modeling and we shouldn't apply that
     * encoding to the generated UUID.
     *
     * @return void
     */
    public function writeIdempotencyToken(
        int $idx,
        int $route,
        string $value,
        Schema $member
    ): void
    {
        $w = $this->writeInfo[$idx];
        switch ($route) {
            case HttpStructBindingExtension::ROUTE_HEADER:
                $this->headers[$w[1]] = [$w[1], [$value]];

                return;
            case HttpStructBindingExtension::ROUTE_QUERY:
                $this->writeQueryPair($w[1], $value);

                return;
            case HttpStructBindingExtension::ROUTE_LABEL:
                $this->labels[$idx] = $value;

                return;
            case HttpStructBindingExtension::ROUTE_BODY_MEMBER:
                $this->codecSerializer->serializeString($member, $value, $idx);

                return;
        }
    }

    private function formatListHeader(array $list, int $idx, int $memberType): string
    {
        switch ($memberType) {
            case ShapeType::Boolean->value:
                return self::joinList(
                    $list,
                    static fn($v) => $v ? 'true' : 'false'
                );
            case ShapeType::Timestamp->value:
                return self::formatTimestampList(
                    $list,
                    $this->listTimestampKinds[$idx]
                        ?? TimestampFormat::HttpDate->value
                );
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                return self::joinList(
                    $list,
                    static fn($v) => match ($s = (string) $v) {
                        'NAN' => 'NaN',
                        'INF' => 'Infinity',
                        '-INF' => '-Infinity',
                        default => $s
                    }
                );
            case ShapeType::Enum->value:
                return self::joinList(
                    $list,
                    static fn($v) => \is_string($v) ? $v : $v->value
                );
            case ShapeType::IntEnum->value:
                return self::joinList(
                    $list,
                    static fn($v) => (string) (
                        \is_int($v) ? $v : $v->value
                    )
                );
            case ShapeType::String_->value:
                return self::joinList(
                    $list,
                    static fn($v) => self::quoteStringHeaderItem((string) $v)
                );
            default:
                return \implode(', ', $list);
        }
    }

    /**
     * Format a single string-header list item per RFC 7230 section3.2.6:
     * if the value contains `,` or `"`, wrap in double quotes and
     * backslash-escape any `"` and `\`. Otherwise return it unchanged.
     */
    private static function quoteStringHeaderItem(string $item): string
    {
        if (\strpbrk($item, ',"') === false) {

            return $item;
        }

        return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $item) . '"';
    }

    /**
     * Percent-encode a greedy label while preserving path separators.
     */
    private static function encodeGreedyLabel(string $value): string
    {
        return \implode('/', \array_map('rawurlencode', \explode('/', $value)));
    }

    private function serializeListQuery(
        array $list,
        string $name,
        int $idx,
        int $memberType
    ): void
    {
        $type = $memberType;

        if ($type === ShapeType::Timestamp->value) {
            switch ($this->listTimestampKinds[$idx]
                ?? TimestampFormat::DateTime->value
            ) {
                case TimestampFormat::DateTime->value:
                    foreach ($list as $v) {
                        $this->writeQueryPair(
                            $name,
                            \gmdate(TimestampFormat::FMT_DATE_TIME, (int) $v)
                        );
                    }
                    break;
                case TimestampFormat::HttpDate->value:
                    foreach ($list as $v) {
                        $this->writeQueryPair(
                            $name,
                            \gmdate(TimestampFormat::FMT_HTTP_DATE, (int) $v)
                        );
                    }
                    break;
                default: // TimestampFormat::EpochSeconds
                    foreach ($list as $v) {
                        $this->writeQueryPair(
                            $name,
                            (string) ($v + 0)
                        );
                    }
                    break;
            }

            return;
        }

        foreach ($list as $v) {
            switch ($type) {
                case ShapeType::Boolean->value:
                    $this->writeQueryPair(
                        $name,
                        $v ? 'true' : 'false'
                    );
                    break;
                case ShapeType::Enum->value:
                    $this->writeQueryPair(
                        $name,
                        \is_string($v) ? $v : $v->value
                    );
                    break;
                case ShapeType::IntEnum->value:
                    $this->writeQueryPair(
                        $name,
                        (string) (\is_int($v) ? $v : $v->value)
                    );
                    break;
                default:
                    $this->writeQueryPair($name, (string) $v);
                    break;
            }
        }
    }

    /**
     * Format a list of epoch timestamps into a comma-separated header value.
     *
     * Format dispatch is hoisted out of the per-item loop. For HttpDate and
     * DateTime, each item is rendered with one gmdate call. EpochSeconds uses
     * string conversion directly.
     *
     * @param list<float> $list
     */
    private static function formatTimestampList(array $list, int $kind): string
    {
        $s = '';
        switch ($kind) {
            case TimestampFormat::DateTime->value:
                foreach ($list as $v) {
                    if ($s !== '') {
                        $s .= ', ';
                    }
                    $s .= \gmdate(TimestampFormat::FMT_DATE_TIME, (int) $v);
                }
                break;
            case TimestampFormat::HttpDate->value:
                foreach ($list as $v) {
                    if ($s !== '') {
                        $s .= ', ';
                    }
                    $s .= \gmdate(TimestampFormat::FMT_HTTP_DATE, (int) $v);
                }
                break;
            default: // TimestampFormat::EpochSeconds
                foreach ($list as $v) {
                    if ($s !== '') {
                        $s .= ', ';
                    }
                    $s .= (string) ($v + 0);
                }
                break;
        }

        return $s;
    }

    private function serializePrefixHeaders(array $map, string $prefix): void
    {
        foreach ($map as $k => $v) {
            $name = $prefix . $k;
            if (isset($this->headerWireNames[\strtolower($name)])) {
                continue;
            }
            $this->headers[$name] = [$name, [(string) $v]];
        }
    }

    private function serializeQueryParams(array $map): void
    {
        foreach ($map as $k => $v) {
            // Conflict check uses the raw key (httpQueryNames is keyed by the
            // unencoded @httpQuery wire name). Encode once per key after the
            // check passes so writeQueryPair can append it as-is.
            if (isset($this->httpQueryNames[$k])) {
                continue;
            }
            $encodedKey = \rawurlencode($k);
            if (\is_array($v)) {
                foreach ($v as $item) {
                    $this->writeQueryPair($encodedKey, (string) $item);
                }
            } else {
                $this->writeQueryPair($encodedKey, (string) $v);
            }
        }
    }

    /**
     * Serialize an @httpPayload value to body bytes.
     *
     * Dispatches on the binding payload member's int-encoded
     * {@see ShapeType}. Per Smithy spec, @httpPayload targets string, blob,
     * structure, or union shapes. The default arm coerces other values to
     * strings defensively.
     *
     * @param mixed $value Captured payload value.
     */
    private function buildPayload(mixed $value): string|StreamInterface
    {
        $payloadSchema = $this->bindingInfo->payloadSchema;
        switch ($payloadSchema->type) {
            case ShapeType::Structure->value:
                $serializer = $this->codec->createSerializer();
                $serializer->serializeStruct($payloadSchema, $value, 0);

                return $serializer->flush();
            case ShapeType::Union->value:
                $serializer = $this->codec->createSerializer();
                $serializer->serializeUnion($payloadSchema, $value, 0);

                return $serializer->flush();
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
            case ShapeType::Blob->value:
                return $value;
            case ShapeType::Document->value:
                // Route document payloads through the codec's document entry
                // point with the payload member schema so nested document
                // values keep their type context during rendering.
                $serializer = $this->codec->createSerializer();
                $serializer->serializeDocument(
                    $payloadSchema,
                    $value,
                    0
                );

                return $serializer->flush();
            default:
                return (string) $value;
        }
    }

    /**
     * Format a floating-point value for HTTP serialization.
     */
    private static function formatFloat(float $value): string
    {
        // Match subject already does the (string) cast. Default returns the
        // same string, so the previous `default => (string) $value` was a
        // wasted re-cast on the hot path.

        return match ($s = (string) $value) {
            'NAN' => 'NaN',
            'INF' => 'Infinity',
            '-INF' => '-Infinity',
            default => $s,
        };
    }

    /**
     * Join list items with comma separator using a formatter callback.
     * Avoids intermediate array allocation from array_map + implode.
     *
     * @param list<mixed> $list
     * @param \Closure(mixed): string $formatter
     */
    private static function joinList(array $list, \Closure $formatter): string
    {
        $s = '';
        foreach ($list as $v) {
            if ($s !== '') {
                $s .= ', ';
            }
            $s .= $formatter($v);
        }

        return $s;
    }
}

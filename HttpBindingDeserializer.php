<?php

declare(strict_types=1);

namespace Smithy\Protocol;

use Psr\Http\Message\StreamInterface;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\Document\AbstractDocument;
use Smithy\Core\Shape\Document\StructDocument;

/**
 * Deserialize HTTP-bound response data into positional shape members.
 *
 * @internal Runtime deserializer for HTTP binding protocols.
 */
final class HttpBindingDeserializer implements ShapeDeserializerInterface
{
    private const RESPONSE_HEADER_SCALAR = 0;
    private const PREFIX_HEADER_EXCLUSIONS = [
        'authorization' => true,
        'connection' => true,
        'content-length' => true,
        'expect' => true,
        'host' => true,
        'max-forwards' => true,
        'proxy-authenticate' => true,
        'server' => true,
        'te' => true,
        'trailer' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
        'user-agent' => true,
        'www-authenticate' => true,
        'x-forwarded-for' => true
    ];

    /** @var array<string, array<int, mixed>> */
    private readonly array $headerBindingLookup;
    /**
     * Headers keyed by lowercase name.
     *
     * @var array<string, array{string, list<string>}>
     */
    private readonly array $headers;
    private readonly ?StreamInterface $body;
    private int $bodySize = -1;
    private ?string $bodyContents = null;
    private readonly int $statusCode;

    /**
     * @param array<string, array{string, list<string>}> $headers
     */
    public function __construct(
        private readonly AbstractCodec $payloadCodec,
        private readonly HttpStructBindingExtension $bindingInfo,
        array $headers,
        ?StreamInterface $body,
        int $statusCode = 0
    )
    {
        $this->headers = $headers;
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headerBindingLookup = $bindingInfo->responseHeaders;
        if (isset($headers['content-length'][1][0])) {
            $this->bodySize = (int) $headers['content-length'][1][0];
        }
    }

    /**
     * Deserialize an HTTP-bound output shape.
     *
     * @return void
     */
    public function deserialize(Schema $schema, array &$members): void
    {
        $bindingInfo = $this->bindingInfo;
        $headerBindingLookup = $this->headerBindingLookup;
        $headers = $this->headers;
        $scalarTimestampFormats = $bindingInfo->scalarTimestampFormats;

        $headerKeySource = \count($headers) <= \count($headerBindingLookup)
            ? $headers
            : $headerBindingLookup;
        foreach ($headerKeySource as $lowerName => $_) {
            $entry = $headers[$lowerName] ?? null;
            $binding = $headerBindingLookup[$lowerName] ?? null;
            if ($entry === null || $binding === null) {
                continue;
            }

            $idx = $binding[1];
            if ($binding[0] === self::RESPONSE_HEADER_SCALAR) {
                switch ($binding[2]) {
                    case ShapeType::String_->value:
                        $members[$idx] = isset($binding[3])
                            ? \base64_decode($entry[1][0], true)
                            : $entry[1][0];
                        break;
                    case ShapeType::Enum->value:
                        $class = $binding[3] ?? null;
                        $members[$idx] = $class !== null
                            ? $class::fromValue($entry[1][0])
                            : $entry[1][0];
                        break;
                    case ShapeType::Integer->value:
                    case ShapeType::Long->value:
                    case ShapeType::Short->value:
                    case ShapeType::Byte->value:
                        $members[$idx] = (int) $entry[1][0];
                        break;
                    case ShapeType::IntEnum->value:
                        $class = $binding[3] ?? null;
                        $intValue = (int) $entry[1][0];
                        $members[$idx] = $class !== null
                            ? $class::fromValue($intValue)
                            : $intValue;
                        break;
                    case ShapeType::Boolean->value:
                        $members[$idx] = $entry[1][0] === 'true';
                        break;
                    case ShapeType::Float_->value:
                    case ShapeType::Double->value:
                        $members[$idx] = $this->parseHeaderFloat(
                            $entry[1][0]
                        );
                        break;
                    case ShapeType::Timestamp->value:
                        $members[$idx] = $scalarTimestampFormats[$idx]
                            ->parse($entry[1][0]);
                        break;
                    default:
                        $members[$idx] = $entry[1][0];
                        break;
                }
            } else {
                switch ($binding[3]) {
                    case ShapeType::Timestamp->value:
                        $members[$idx] = $this->parseTimestampList(
                            $entry[1],
                            $idx
                        );
                        break;
                    case ShapeType::Integer->value:
                    case ShapeType::Long->value:
                    case ShapeType::Short->value:
                    case ShapeType::Byte->value:
                        $members[$idx] = $this->parseIntegerList($entry[1]);
                        break;
                    case ShapeType::Boolean->value:
                        $members[$idx] = $this->parseBooleanList($entry[1]);
                        break;
                    case ShapeType::Float_->value:
                    case ShapeType::Double->value:
                        $members[$idx] = $this->parseFloatList($entry[1]);
                        break;
                    case ShapeType::Enum->value:
                    case ShapeType::IntEnum->value:
                        $members[$idx] = $this->parseEnumList(
                            $entry[1],
                            $binding[4],
                            $binding[3] === ShapeType::IntEnum->value
                        );
                        break;
                    default:
                        $members[$idx] = $this->parseStringList($entry[1]);
                        break;
                }
            }
        }

        if ($bindingInfo->prefixHeaderDeserMembers !== []) {
            foreach ($bindingInfo->prefixHeaderDeserMembers
                as $idx => $prefix
            ) {
                $members[$idx] = $this->parsePrefixHeaders(
                    $prefix[0],
                    $prefix[1]
                );
            }
        }

        if ($bindingInfo->responseCodeMemberIndex !== null) {
            $members[$bindingInfo->responseCodeMemberIndex] = $this->statusCode;
        }

        if ($bindingInfo->payloadMemberIndex !== null) {
            $idx = $bindingInfo->payloadMemberIndex;
            $payloadSchema = $bindingInfo->payloadSchema;
            switch ($payloadSchema->type) {
                case ShapeType::Blob->value:
                    $members[$idx] = $this->body;
                    break;
                case ShapeType::String_->value:
                case ShapeType::Enum->value:
                    $members[$idx] = $this->bodyHasContent()
                        ? $this->bodyContents()
                        : '';
                    break;
                case ShapeType::Document->value:
                    if (!$this->bodyHasContent()) {
                        $members[$idx] = null;
                    } else {
                        $decoded = \json_decode($this->bodyContents(), true);
                        $members[$idx] = AbstractDocument::fromValue(
                            $decoded,
                            $payloadSchema
                        );
                    }
                    break;
                case ShapeType::Union->value:
                    $payloadMembers = $this->deserializePayload($payloadSchema);
                    $members[$idx] = \is_array($payloadMembers)
                        ? ($payloadMembers[0] ?? null)
                        : $payloadMembers;
                    break;
                default:
                    $payloadMembers = $this->deserializePayload($payloadSchema);
                    $targetClass = $payloadSchema->targetClass;
                    if (!\is_array($payloadMembers)) {
                        $members[$idx] = $payloadMembers;
                    } elseif ($targetClass !== null) {
                        $members[$idx] = $targetClass::fromMembers(
                            $payloadMembers
                        );
                    } else {
                        $members[$idx] = StructDocument::fromMembers(
                            $payloadSchema,
                            $payloadMembers
                        );
                    }
                    break;

            }
        } else {
            $body = $this->body;
            $bodySchema = $bindingInfo->responseBodySchema;
            if ($bodySchema === null
                || $body === null
                || !$this->bodyHasContent()
            ) {
                return;
            }

            $this->payloadCodec->createDeserializer($body)->deserialize(
                $bodySchema,
                $members
            );
        }
    }

    /**
     * Deserialize a structure/union payload into a positional members array.
     */
    private function deserializePayload(Schema $schema): mixed
    {
        $body = $this->body;
        if ($body === null || !$this->bodyHasContent()) {
            return null;
        }

        $memberCount = $schema->getMemberCount();
        $payloadMembers = $memberCount > 0
            ? \array_fill(0, $memberCount, null)
            : [];
        $this->payloadCodec->createDeserializer($body)->deserialize(
            $schema,
            $payloadMembers
        );

        return $payloadMembers;
    }

    private function bodyHasContent(): bool
    {
        if ($this->body === null) {
            return false;
        }

        if ($this->bodySize >= 0) {
            return $this->bodySize > 0;
        }

        $bodySize = $this->body->getSize();

        return $bodySize === null || $bodySize > 0;
    }

    private function bodyContents(): string
    {
        return $this->bodyContents ??= (string) $this->body;
    }

    /**
     * Parse a list-valued header into an array of strings.
     *
     * @param list<string> $values Raw header values
     *
     * @return list<string>
     */
    private function parseStringList(array $values): array
    {
        if (\count($values) > 1) {
            return \array_map('trim', $values);
        }
        $raw = $values[0];
        // RFC 7230 quoted-string parsing is only needed for values containing
        // commas or quotes.
        if (!\str_contains($raw, ',') && !\str_contains($raw, '"')) {
            return [\trim($raw)];
        }

        return self::splitQuotedHeaderList($raw);
    }

    /**
     * Split a single header value using RFC 7230 quoted-string syntax.
     *
     * Items containing `,` or `"` arrive wrapped in double quotes with `\`
     * and `"` backslash-escaped. Only commas outside quoted regions split
     * list items.
     *
     * @return list<string>
     */
    private static function splitQuotedHeaderList(string $raw): array
    {
        $items = [];
        $len = \strlen($raw);
        $i = 0;
        while ($i < $len) {
            // Skip leading whitespace before each item.
            while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t")) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($raw[$i] === '"') {
                // Quoted items honor `\X` as an escape for the next char.
                $i++;
                $buf = '';
                while ($i < $len) {
                    $ch = $raw[$i];
                    if ($ch === '\\' && $i + 1 < $len) {
                        $buf .= $raw[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($ch === '"') {
                        $i++;
                        break;
                    }
                    $buf .= $ch;
                    $i++;
                }
                $items[] = $buf;
                while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t")) {
                    $i++;
                }
                if ($i < $len && $raw[$i] === ',') {
                    $i++;
                }
                continue;
            }
            $start = $i;
            while ($i < $len && $raw[$i] !== ',') {
                $i++;
            }
            $items[] = \rtrim(\substr($raw, $start, $i - $start));
            if ($i < $len && $raw[$i] === ',') {
                $i++;
            }
        }
        return $items;
    }

    /**
     * Parse a list-valued timestamp header.
     *
     * HttpDate embeds a comma, so multi-item HttpDate lists use the fixed
     * IMF-fixdate width instead of splitting on comma.
     *
     * @param list<string> $values Raw header values
     * @param int $memberIndex Member index in the response shape
     *
     * @return list<float>
     */
    private function parseTimestampList(array $values, int $memberIndex): array
    {
        $kind = $this->bindingInfo->listTimestampKinds[$memberIndex]
            ?? TimestampFormat::HttpDate->value;
        $joined = \implode(', ', $values);

        if ($kind === TimestampFormat::HttpDate->value) {
            $items = [];
            $len = \strlen($joined);
            for ($o = 0; $o < $len; $o += 31) {
                $items[] = \substr($joined, $o, 29);
            }
        } else {
            $items = \explode(',', $joined);
        }

        $result = [];
        $format = match ($kind) {
            TimestampFormat::DateTime->value => TimestampFormat::DateTime,
            TimestampFormat::HttpDate->value => TimestampFormat::HttpDate,
            default => TimestampFormat::EpochSeconds
        };
        foreach ($items as $item) {
            $result[] = $format->parse(\trim($item));
        }

        return $result;
    }

    /**
     * Parse a float/double header value with NaN/Infinity support.
     */
    private function parseHeaderFloat(string $value): float
    {
        return match ($value) {
            'NaN' => NAN,
            'Infinity' => INF,
            '-Infinity' => -INF,
            default => (float) $value,
        };
    }

    /**
     * Parse a list-valued integer header.
     *
     * @param list<string> $values Raw header values
     *
     * @return list<int>
     */
    private function parseIntegerList(array $values): array
    {
        $items = \count($values) > 1 ? $values : \explode(',', $values[0]);
        $result = [];
        foreach ($items as $v) {
            $result[] = (int) \trim($v);
        }

        return $result;
    }

    /**
     * Parse a list-valued boolean header.
     *
     * @param list<string> $values Raw header values
     *
     * @return list<bool>
     */
    private function parseBooleanList(array $values): array
    {
        $items = \count($values) > 1 ? $values : \explode(',', $values[0]);
        $result = [];
        foreach ($items as $v) {
            $result[] = \trim($v) === 'true';
        }

        return $result;
    }

    /**
     * Parse a list-valued float header with NaN/Infinity support.
     *
     * @param list<string> $values Raw header values
     *
     * @return list<float>
     */
    private function parseFloatList(array $values): array
    {
        $items = \count($values) > 1 ? $values : \explode(',', $values[0]);
        $result = [];
        foreach ($items as $v) {
            $result[] = $this->parseHeaderFloat(\trim($v));
        }

        return $result;
    }

    /**
     * Parse a list-valued enum or intEnum header.
     *
     * @param list<string> $values Raw header values.
     * @param class-string|null $class FQCN of the target enum class.
     * @param bool $isIntEnum True for IntEnum (parse items as int first)
     *
     * @return list<object|string|int>
     */
    private function parseEnumList(
        array $values,
        ?string $class,
        bool $isIntEnum
    ): array {
        $items = \count($values) > 1 ? $values : \explode(',', $values[0]);
        $result = [];
        if ($isIntEnum) {
            foreach ($items as $v) {
                $value = (int) \trim($v);
                $result[] = $class !== null
                    ? $class::fromValue($value)
                    : $value;
            }
        } else {
            foreach ($items as $v) {
                $value = \trim($v);
                $result[] = $class !== null
                    ? $class::fromValue($value)
                    : $value;
            }
        }

        return $result;
    }

    /**
     * Collect prefix headers into a map.
     *
     * @param string $lowerPrefix Lowercase prefix to match
     * @param int $prefixLen Length of the prefix for substr offset
     *
     * @return array<string, string>
     */
    private function parsePrefixHeaders(string $lowerPrefix, int $prefixLen): array
    {
        $map = [];
        foreach ($this->headers as $lower => $entry) {
            if (!\str_starts_with($lower, $lowerPrefix)) {
                continue;
            }
            if (isset(self::PREFIX_HEADER_EXCLUSIONS[$lower])) {
                continue;
            }

            $map[\substr($entry[0], $prefixLen)] = $entry[1][0];
        }

        return $map;
    }
}

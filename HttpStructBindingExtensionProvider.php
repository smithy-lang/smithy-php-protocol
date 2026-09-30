<?php

declare(strict_types=1);

namespace Smithy\Protocol;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SchemaExtensionKey;
use Smithy\Core\Serde\SchemaExtensionProviderInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Http\Message\HttpMessageValidator;

/**
 * Provide HTTP binding metadata for structure and union schemas.
 *
 * @internal Runtime metadata provider for HTTP-bound structure schemas.
 */
final class HttpStructBindingExtensionProvider
    implements SchemaExtensionProviderInterface
{
    /**
     * Singleton extension key.
     */
    public static ?SchemaExtensionKey $KEY = null;

    /**
     * Return the singleton extension key.
     */
    public static function key(): SchemaExtensionKey
    {
        return self::$KEY ??= new SchemaExtensionKey(new self());
    }

    /**
     * Provide HTTP binding metadata for a structure or union schema.
     */
    public function provide(Schema $schema): ?HttpStructBindingExtension
    {
        if ($schema->type !== ShapeType::Structure->value
            && $schema->type !== ShapeType::Union->value
        ) {
            return null;
        }

        $memberRoutes = [];
        $writeInfo = [];
        $headerMembers = [];
        $headerWireNames = [];
        $queryMembers = [];
        $prefixHeaderMembers = [];
        $httpQueryNames = [];
        $bodyMemberIndices = [];
        $responseBodyMemberIndices = [];
        $payloadSchema = null;
        $payloadMediaType = null;
        $requestTimestampKinds = [];
        $idempotencyTokenMemberIndex = null;

        $responseHeaders = [];
        $prefixHeaderDeserMembers = [];
        $responseCodeMemberIndex = null;
        $payloadMemberIndex = null;

        $scalarTimestampFormats = [];
        $listTimestampKinds = [];

        foreach ($schema->getMembers() as $member) {
            $idx = $member->memberIndex;

            if ($member->hasTrait(Schema::T_IDEMPOTENCY_TOKEN)) {
                $idempotencyTokenMemberIndex = $idx;
            }

            if ($member->hasTrait(Schema::T_HTTP_HEADER)) {
                $rawHeader = $member->getTrait(Schema::T_HTTP_HEADER);
                HttpMessageValidator::validateHeaderName($rawHeader);
                $lowerName = \strtolower($rawHeader);

                $headerMembers[$idx] = $lowerName;
                $headerWireNames[$lowerName] = true;
                $memberRoutes[$idx] = HttpStructBindingExtension::ROUTE_HEADER;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_HEADER,
                    $lowerName
                ];

                if ($member->type === ShapeType::List_->value) {
                    $listMember = $member->getListMember();
                    $listItemType = $listMember?->type
                        ?? ShapeType::String_->value;
                    $listItemClass = (
                        $listItemType === ShapeType::Enum->value
                            || $listItemType === ShapeType::IntEnum->value
                    ) ? self::resolveEnumTargetClass($listMember) : null;
                    $responseHeaders[$lowerName] = [
                        1,
                        $idx,
                        $member,
                        $listItemType,
                        $listItemClass
                    ];

                    if ($listItemType === ShapeType::Timestamp->value
                        && $listMember !== null
                    ) {
                        $tf = TimestampFormat::fromTraitString(
                            $listMember->getTrait(Schema::T_TIMESTAMP_FORMAT)
                        );

                        if ($tf !== null) {
                            $listTimestampKinds[$idx] = $tf->value;
                        }
                    }
                } else {
                    $hasMediaType = $member->type === ShapeType::String_->value
                        && $member->hasTrait(Schema::T_MEDIA_TYPE);
                    if ($hasMediaType) {
                        $responseHeaders[$lowerName] = [
                            0,
                            $idx,
                            $member->type,
                            true
                        ];
                        $writeInfo[$idx] = [
                            HttpStructBindingExtension::ROUTE_HEADER,
                            $lowerName,
                            true
                        ];
                    } elseif ($member->type === ShapeType::Enum->value
                        || $member->type === ShapeType::IntEnum->value
                    ) {
                        $responseHeaders[$lowerName] = [
                            0,
                            $idx,
                            $member->type,
                            self::resolveEnumTargetClass($member)
                        ];
                    } else {
                        $responseHeaders[$lowerName] = [0, $idx, $member->type];
                    }

                    if ($member->type === ShapeType::Timestamp->value) {
                        $resolved = TimestampFormat::fromTraitString(
                            $member->getTrait(Schema::T_TIMESTAMP_FORMAT)
                        ) ?? TimestampFormat::HttpDate;
                        $scalarTimestampFormats[$idx] = $resolved;
                        $requestTimestampKinds[$idx] = $resolved->value;
                    }
                }
            } elseif ($member->hasTrait(Schema::T_HTTP_QUERY)) {
                $queryName = $member->getTrait(Schema::T_HTTP_QUERY);
                $encodedName = \rawurlencode($queryName);
                $queryMembers[$idx] = $encodedName;
                $httpQueryNames[$queryName] = true;
                $memberRoutes[$idx] = HttpStructBindingExtension::ROUTE_QUERY;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_QUERY,
                    $encodedName
                ];
                $responseBodyMemberIndices[] = $idx;

                if ($member->type === ShapeType::Timestamp->value) {
                    $format = TimestampFormat::fromTraitString(
                        $member->getTrait(Schema::T_TIMESTAMP_FORMAT)
                    ) ?? TimestampFormat::DateTime;
                    $requestTimestampKinds[$idx] = $format->value;
                }

                if ($member->type === ShapeType::List_->value) {
                    $listMember = $member->getListMember();
                    if ($listMember !== null
                        && $listMember->type === ShapeType::Timestamp->value
                    ) {
                        $tf = TimestampFormat::fromTraitString(
                            $listMember->getTrait(Schema::T_TIMESTAMP_FORMAT)
                        );

                        if ($tf !== null) {
                            $listTimestampKinds[$idx] = $tf->value;
                        }
                    }
                }
            } elseif ($member->hasTrait(Schema::T_HTTP_QUERY_PARAMS)) {
                $memberRoutes[$idx] =
                    HttpStructBindingExtension::ROUTE_QUERY_PARAMS;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_QUERY_PARAMS,
                    null
                ];
                $responseBodyMemberIndices[] = $idx;
            } elseif ($member->hasTrait(Schema::T_HTTP_PREFIX_HEADERS)) {
                $prefix = $member->getTrait(Schema::T_HTTP_PREFIX_HEADERS);
                if ($prefix !== '') {
                    HttpMessageValidator::validateHeaderName($prefix);
                }
                $lowerPrefix = \strtolower($prefix);
                $prefixHeaderMembers[$idx] = $lowerPrefix;
                $prefixHeaderDeserMembers[$idx] = [
                    $lowerPrefix,
                    \strlen($prefix)
                ];
                $memberRoutes[$idx] =
                    HttpStructBindingExtension::ROUTE_PREFIX_HEADERS;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_PREFIX_HEADERS,
                    $lowerPrefix
                ];
            } elseif ($member->hasTrait(Schema::T_HTTP_LABEL)) {
                $memberRoutes[$idx] = HttpStructBindingExtension::ROUTE_LABEL;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_LABEL,
                    null
                ];
                $responseBodyMemberIndices[] = $idx;

                if ($member->type === ShapeType::Timestamp->value) {
                    $format = TimestampFormat::fromTraitString(
                        $member->getTrait(Schema::T_TIMESTAMP_FORMAT)
                    ) ?? TimestampFormat::DateTime;
                    $requestTimestampKinds[$idx] = $format->value;
                }
            } elseif ($member->hasTrait(Schema::T_HTTP_PAYLOAD)) {
                $memberRoutes[$idx] = HttpStructBindingExtension::ROUTE_PAYLOAD;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_PAYLOAD,
                    null
                ];
                $payloadSchema = $member;
                $payloadMemberIndex = $idx;
                $payloadMediaType = $member->getTrait(Schema::T_MEDIA_TYPE);
            } elseif ($member->hasTrait(Schema::T_HTTP_RESPONSE_CODE)) {
                $memberRoutes[$idx] =
                    HttpStructBindingExtension::ROUTE_RESPONSE_CODE;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_BODY_MEMBER,
                    null
                ];
                $responseCodeMemberIndex = $idx;
                $bodyMemberIndices[] = $idx;
            } else {
                $bodyMemberIndices[] = $idx;
                $responseBodyMemberIndices[] = $idx;
                $memberRoutes[$idx] =
                    HttpStructBindingExtension::ROUTE_BODY_MEMBER;
                $writeInfo[$idx] = [
                    HttpStructBindingExtension::ROUTE_BODY_MEMBER,
                    null
                ];
            }
        }

        return new HttpStructBindingExtension(
            memberRoutes: $memberRoutes,
            writeInfo: $writeInfo,
            headerMembers: $headerMembers,
            headerWireNames: $headerWireNames,
            queryMembers: $queryMembers,
            prefixHeaderMembers: $prefixHeaderMembers,
            httpQueryNames: $httpQueryNames,
            bodyMemberIndices: $bodyMemberIndices,
            bodySchema: $bodyMemberIndices !== []
                ? $schema->withMembers($bodyMemberIndices)
                : null,
            payloadSchema: $payloadSchema,
            payloadMediaType: $payloadMediaType,
            requestTimestampKinds: $requestTimestampKinds,
            idempotencyTokenMemberIndex: $idempotencyTokenMemberIndex,
            responseHeaders: $responseHeaders,
            prefixHeaderDeserMembers: $prefixHeaderDeserMembers,
            responseCodeMemberIndex: $responseCodeMemberIndex,
            payloadMemberIndex: $payloadMemberIndex,
            responseBodyMemberIndices: $responseBodyMemberIndices,
            responseBodySchema: $responseBodyMemberIndices !== []
                ? $schema->withMembers($responseBodyMemberIndices)
                : null,
            scalarTimestampFormats: $scalarTimestampFormats,
            listTimestampKinds: $listTimestampKinds
        );
    }

    private static function resolveEnumTargetClass(?Schema $schema): ?string
    {
        if ($schema === null) {
            return null;
        }

        return $schema->targetClass
            ?? $schema->resolveTarget()->targetClass;
    }
}

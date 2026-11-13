<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Protocol\Aws;

use Psr\Http\Message\ResponseInterface;
use Smithy\Core\Serde\Schema;
use Smithy\Http\Message\StringRequestStream;
use Smithy\Protocol\HttpBindingDeserializer;
use Smithy\Protocol\HttpStructBindingExtension;
use Smithy\Protocol\HttpStructBindingExtensionProvider;

/**
 * Deserialize Rest XML errors with HTTP-bound members.
 */
final class RestXmlErrorDeserializer extends AwsXmlErrorDeserializer
{
    /**
     * @param array<int, mixed> $members Positional member values.
     */
    protected function deserializeMembers(
        Schema $schema,
        array &$members,
        ResponseInterface $response,
        string $body
    ): void
    {
        static $bindingKey = null;
        $bindingKey ??= HttpStructBindingExtensionProvider::$KEY
            ?? HttpStructBindingExtensionProvider::key();

        /** @var HttpStructBindingExtension|null $bindingInfo */
        $bindingInfo = $schema->getExtension($bindingKey);
        if ($bindingInfo === null) {
            parent::deserializeMembers(
                $schema,
                $members,
                $response,
                $body
            );

            return;
        }

        $deserializer = new HttpBindingDeserializer(
            $this->codec,
            $bindingInfo,
            self::normalizeHeaders($response),
            new StringRequestStream($body),
            $response->getStatusCode()
        );
        $deserializer->deserialize($schema, $members);
    }
}

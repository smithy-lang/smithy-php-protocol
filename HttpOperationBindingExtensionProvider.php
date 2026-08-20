<?php

declare(strict_types=1);

namespace Smithy\Protocol;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SchemaExtensionKey;
use Smithy\Core\Serde\SchemaExtensionProviderInterface;

/**
 * Provide HTTP operation binding metadata for operation schemas.
 *
 * @internal Runtime metadata provider for HTTP-bound operation schemas.
 */
final class HttpOperationBindingExtensionProvider implements SchemaExtensionProviderInterface
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

    public function provide(Schema $schema): HttpOperationBindingExtension
    {
        $http = $schema->getTrait(Schema::T_HTTP);
        if ($http === null) {
            return new HttpOperationBindingExtension();
        }

        $uri = (string) ($http['uri'] ?? '/');
        $path = $uri;
        $qmark = \strpos($uri, '?');
        if ($qmark !== false) {
            $path = \substr($uri, 0, $qmark);
        }

        $pathQueryString = $http['pathQueryString'] ?? null;
        if ($pathQueryString === null
            && isset($http['pathQueryPairs'])
        ) {
            $fragments = [];
            foreach ($http['pathQueryPairs'] as $pair) {
                $fragments[] = $pair[0] . '=' . $pair[1];
            }
            $pathQueryString = \implode('&', $fragments);
        }

        return new HttpOperationBindingExtension(
            method: (string) ($http['method'] ?? 'POST'),
            path: $path,
            pathSegments: $http['pathSegments'] ?? null,
            pathQueryString: $pathQueryString,
            httpResponseCode: isset($http['code']) ? (int) $http['code'] : null
        );
    }
}

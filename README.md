# Smithy PHP Protocol

Converts Smithy operations and shapes to HTTP requests and responses. Requires
PHP 8.2 or later.

## Installation

```shell
composer require smithy/protocol
```

## Protocol Contract

`ProtocolInterface` defines the request and response boundary:

```php
$request = $protocol->serializeRequest(
    $operation,
    $input,
    '/',
);

$request = $protocol->setServiceEndpoint(
    $request,
    $endpoint,
);

$output = $protocol->deserializeResponse(
    $operation,
    $response,
);
```

Clients serialize an operation-relative request once before the retry loop.
Each request attempt resolves and binds its service endpoint before signing.

Operations supply input, output, error, and HTTP metadata through runtime
schemas. Protocol implementations use codecs for body encoding.

## HTTP Bindings

`AbstractHttpBindingProtocol` handles Smithy HTTP binding traits. It composes:

- `HttpBindingSerializer` for URI labels, queries, headers, payloads, and
  document bodies
- `HttpBindingDeserializer` for response headers, status codes, payloads, and
  document bodies
- `HttpErrorDeserializer` for modeled and unmodeled service errors

Schema extension providers precompute operation and structure binding data.
Each request reads the precomputed binding routes.

## RPC v2 CBOR

`RpcV2CborProtocol` implements the public `smithy.protocols#rpcv2Cbor`
protocol using the CBOR codec.

```php
use Smithy\Protocol\RpcV2\RpcV2CborProtocol;

$protocol = new RpcV2CborProtocol();
```

Provider packages compose their protocol implementations from the shared HTTP
binding, codec, and error-deserialization components.

## Component Boundaries

Codecs own body formats such as JSON, XML, and CBOR. The HTTP package owns
messages and transfer behavior. This package owns operation framing, HTTP
member routing, and response interpretation.

## Dependencies

This package depends on `smithy/codec`, `smithy/core`, `smithy/http`, and
`psr/http-message`.

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Protocol
```

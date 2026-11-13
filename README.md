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
Protocols declare their request/response message exchange so configuration
can reject an incompatible transport before execution. HTTP protocols use
`HttpTransportRequest` and `HttpTransportResponse`, whose payloads remain
PSR-7 messages at the HTTP boundary.

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

## RPC v2 JSON

`RpcV2JsonProtocol` implements the public `smithy.protocols#rpcv2Json`
protocol using the JSON codec.

```php
use Smithy\Protocol\RpcV2\RpcV2JsonProtocol;

$protocol = new RpcV2JsonProtocol();
```

## AWS JSON

`AwsJson1_0Protocol` implements `aws.protocols#awsJson1_0` with AWS JSON RPC
framing, the `application/x-amz-json-1.0` content type, and AWS JSON error
envelopes.

```php
use Smithy\Protocol\Aws\AwsJson1_0Protocol;

$protocol = new AwsJson1_0Protocol();
```

`AwsJson1_1Protocol` implements `aws.protocols#awsJson1_1` with AWS JSON RPC
framing, the `application/x-amz-json-1.1` content type, and AWS JSON error
envelopes.

```php
use Smithy\Protocol\Aws\AwsJson1_1Protocol;

$protocol = new AwsJson1_1Protocol();
```

## AWS Rest JSON

`RestJsonProtocol` implements `aws.protocols#restJson1` with Smithy HTTP
bindings, the JSON codec, and AWS JSON error envelopes.

```php
use Smithy\Protocol\Aws\RestJsonProtocol;

$protocol = new RestJsonProtocol();
```

## AWS Rest XML

`RestXmlProtocol` implements `aws.protocols#restXml` with Smithy HTTP bindings,
the XML codec, and AWS XML error envelopes.

```php
use Smithy\Protocol\Aws\RestXmlProtocol;

$protocol = new RestXmlProtocol();
```

## AWS Query

`AwsQueryProtocol` implements `aws.protocols#awsQuery` with form URL-encoded
requests and XML responses.

```php
use Smithy\Protocol\Aws\AwsQueryProtocol;

$protocol = new AwsQueryProtocol('2020-01-08');
```

Pass the service version from the Smithy service shape. The protocol sends it
with the operation action in every request body.

## EC2 Query

`Ec2QueryProtocol` implements `aws.protocols#ec2Query` with EC2-specific form
member names, flattened lists, and XML response wrappers.

```php
use Smithy\Protocol\Aws\Ec2QueryProtocol;

$protocol = new Ec2QueryProtocol('2020-01-08');
```

Pass the service version from the Smithy service shape.

## Component Boundaries

Codecs own body formats such as JSON, XML, and CBOR. The HTTP package owns
messages and transfer behavior. This package owns operation framing, HTTP
member routing, and response interpretation.

## Dependencies

This package depends on `smithy/codec`, `smithy/core`, `smithy/http`, and
`psr/http-message`.

## Design

See [Protocol Design](../../docs/design/protocol/protocol-design.md).

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Protocol
```

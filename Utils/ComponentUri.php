<?php

declare(strict_types=1);

namespace Smithy\Protocol\Utils;

use Psr\Http\Message\UriInterface;

/**
 * Store pre-validated URI components for internal protocol construction.
 *
 * No \parse_url() or regex filtering is done. Protocol serializers pass
 * components that were already assembled and encoded. Public mutation methods
 * preserve that trusted-input contract and do not validate or encode values.
 *
 * Immutable value object. All with*() methods return a new instance.
 *
 * @internal
 */
final class ComponentUri implements UriInterface
{
    private const DEFAULT_PORTS = [
        'http' => 80,
        'https' => 443,
    ];

    /**
     * Create a URI from pre-validated components.
     */
    public function __construct(
        private readonly string $scheme = '',
        private readonly string $host = '',
        private readonly ?int $port = null,
        private readonly string $path = '',
        private readonly string $query = '',
        private readonly string $fragment = '',
        private readonly string $userInfo = '',
    ) {
    }

    /**
     * Get the URI scheme.
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Get the URI authority.
     */
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    /**
     * Get the URI user information.
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * Get the URI host.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the URI port.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Get the URI path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the URI query string.
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Get the URI fragment.
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * Return a URI with a replaced scheme.
     */
    public function withScheme(string $scheme): UriInterface
    {
        $scheme = \strtolower($scheme);
        if ($scheme === $this->scheme) {
            return $this;
        }

        $port = $this->port;
        if (
            $port !== null
            && isset(self::DEFAULT_PORTS[$scheme])
            && $port === self::DEFAULT_PORTS[$scheme]
        ) {
            $port = null;
        }

        return new self(
            $scheme,
            $this->host,
            $port,
            $this->path,
            $this->query,
            $this->fragment,
            $this->userInfo,
        );
    }

    /**
     * Return a URI with replaced user information.
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $info = $user;
        if ($password !== null && $password !== '') {
            $info .= ':' . $password;
        }

        if ($info === $this->userInfo) {
            return $this;
        }

        return new self(
            $this->scheme,
            $this->host,
            $this->port,
            $this->path,
            $this->query,
            $this->fragment,
            $info,
        );
    }

    /**
     * Return a URI with a replaced host.
     */
    public function withHost(string $host): UriInterface
    {
        $host = \strtolower($host);
        if ($host === $this->host) {
            return $this;
        }

        return new self(
            $this->scheme,
            $host,
            $this->port,
            $this->path,
            $this->query,
            $this->fragment,
            $this->userInfo,
        );
    }

    /**
     * Return a URI with a replaced port.
     */
    public function withPort(?int $port): UriInterface
    {
        if ($port !== null) {
            if ($port < 0 || $port > 65535) {
                throw new \InvalidArgumentException(
                    "Invalid port: {$port}. Must be between 0 and 65535."
                );
            }
            $defaultPort = self::DEFAULT_PORTS[$this->scheme] ?? null;
            if ($port === $defaultPort) {
                $port = null;
            }
        }

        if ($port === $this->port) {
            return $this;
        }

        return new self(
            $this->scheme,
            $this->host,
            $port,
            $this->path,
            $this->query,
            $this->fragment,
            $this->userInfo,
        );
    }

    /**
     * Return a URI with a replaced path.
     */
    public function withPath(string $path): UriInterface
    {
        if ($path === $this->path) {
            return $this;
        }

        return new self(
            $this->scheme,
            $this->host,
            $this->port,
            $path,
            $this->query,
            $this->fragment,
            $this->userInfo,
        );
    }

    /**
     * Return a URI with a replaced query string.
     */
    public function withQuery(string $query): UriInterface
    {
        if ($query === $this->query) {
            return $this;
        }

        return new self(
            $this->scheme,
            $this->host,
            $this->port,
            $this->path,
            $query,
            $this->fragment,
            $this->userInfo,
        );
    }

    /**
     * Return a URI with a replaced fragment.
     */
    public function withFragment(string $fragment): UriInterface
    {
        if ($fragment === $this->fragment) {
            return $this;
        }

        return new self(
            $this->scheme,
            $this->host,
            $this->port,
            $this->path,
            $this->query,
            $fragment,
            $this->userInfo,
        );
    }

    /**
     * Render the URI string.
     */
    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;
        if ($path !== '') {
            if ($authority !== '' && !\str_starts_with($path, '/')) {
                $path = '/' . $path;
            } elseif ($authority === '' && \str_starts_with($path, '//')) {
                $path = '/' . \ltrim($path, '/');
            }
        }

        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }
}

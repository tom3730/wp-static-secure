<?php

declare(strict_types=1);

namespace WPStaticSecure\Configuration;

use InvalidArgumentException;

final class Origin
{
    private string $value;

    public function __construct(string $origin)
    {
        $parts = parse_url($origin);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Origin must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Origin scheme must be http or https.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Origin must not contain credentials, query, or fragment.');
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('Origin must not contain a path.');
        }

        $host = strtolower($parts['host']);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $host) !== 1) {
            throw new InvalidArgumentException('Origin host is invalid.');
        }

        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $formattedHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $this->value = $scheme . '://' . $formattedHost . ($port !== null ? ':' . $port : '');
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

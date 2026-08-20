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
        $unwrappedHost = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        $isIpv4 = filter_var($unwrappedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($unwrappedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $isDnsHost = !$isIpv4 && !$isIpv6 && self::isValidDnsHost($unwrappedHost);

        if ($unwrappedHost === '' || (!$isIpv4 && !$isIpv6 && !$isDnsHost)) {
            throw new InvalidArgumentException('Origin host is invalid.');
        }

        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $formattedHost = $isIpv6 ? '[' . $unwrappedHost . ']' : $unwrappedHost;
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

    private static function isValidDnsHost(string $host): bool
    {
        if (strlen($host) > 253 || preg_match('/^[0-9.]+$/', $host) === 1) {
            return false;
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }
}

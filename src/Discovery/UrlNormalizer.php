<?php

declare(strict_types=1);

namespace WPStaticSecure\Discovery;

use InvalidArgumentException;

final class UrlNormalizer
{
    public function normalize(string $url, ?string $baseUrl = null): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('URL must not be empty.');
        }

        self::rejectUnsafeCharacters($url);

        if ($baseUrl !== null && !$this->isAbsoluteReference($url)) {
            $url = $this->resolve($url, $this->normalize($baseUrl));
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('URL must be absolute or resolvable against an absolute base URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https URLs are crawlable.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URLs containing credentials are not crawlable.');
        }

        $host = strtolower($parts['host']);
        $host = $this->normalizeHost($host);

        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $path = $this->normalizePath($path);

        $query = null;
        if (array_key_exists('query', $parts)) {
            $query = self::normalizePercentEncoding((string) $parts['query'], false);
        }

        return $scheme
            . '://' . $host
            . ($port !== null ? ':' . $port : '')
            . $path
            . ($query !== null ? '?' . $query : '');
    }

    private function resolve(string $reference, string $baseUrl): string
    {
        if (str_starts_with($reference, '//')) {
            $base = parse_url($baseUrl);
            return $base['scheme'] . ':' . $reference;
        }

        if (str_starts_with($reference, '#')) {
            return preg_replace('/#.*$/', '', $baseUrl) . $reference;
        }

        if (str_starts_with($reference, '?')) {
            $base = parse_url($baseUrl);
            return $this->authority($base)
                . ($base['path'] ?? '/')
                . $reference;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            throw new InvalidArgumentException('Base URL must be absolute.');
        }

        $authority = $this->authority($base);
        if (str_starts_with($reference, '/')) {
            return $authority . $reference;
        }

        $basePath = $base['path'] ?? '/';
        $directory = str_ends_with($basePath, '/')
            ? $basePath
            : substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return $authority . $directory . $reference;
    }

    /** @param array<string, mixed> $parts */
    private function authority(array $parts): string
    {
        $authority = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority;
    }

    private function normalizePath(string $path): string
    {
        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException('Backslashes are not allowed in crawl paths.');
        }

        if (preg_match('/%(?:2f|5c)/i', $path) === 1) {
            throw new InvalidArgumentException('Encoded path separators are not allowed in crawl paths.');
        }

        $path = self::normalizePercentEncoding($path, true);
        $segments = explode('/', $path);
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        $result = '/' . implode('/', $normalized);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $result .= '/';
        }

        return $result === '' ? '/' : $result;
    }

    private function normalizeHost(string $host): string
    {
        $unwrapped = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        $isIpv4 = filter_var($unwrapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($unwrapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if ($isIpv6) {
            return '[' . strtolower($unwrapped) . ']';
        }

        if ($isIpv4) {
            return $unwrapped;
        }

        if (!self::isValidDnsHost($unwrapped)) {
            throw new InvalidArgumentException('URL host is invalid.');
        }

        return $unwrapped;
    }

    private function isAbsoluteReference(string $url): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url) === 1;
    }

    private static function rejectUnsafeCharacters(string $value): void
    {
        if (preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('URLs must not contain whitespace or control characters.');
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            throw new InvalidArgumentException('URL contains malformed percent encoding.');
        }
    }

    private static function normalizePercentEncoding(string $value, bool $decodeDots): string
    {
        return (string) preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function (array $match) use ($decodeDots): string {
                $byte = hexdec($match[1]);
                $char = chr($byte);
                $isUnreserved = preg_match('/[A-Za-z0-9\-._~]/', $char) === 1;

                if ($isUnreserved && ($decodeDots || $char !== '.')) {
                    return $char;
                }

                return '%' . strtoupper($match[1]);
            },
            $value
        );
    }

    private static function isValidDnsHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/^[0-9.]+$/', $host) === 1) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
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

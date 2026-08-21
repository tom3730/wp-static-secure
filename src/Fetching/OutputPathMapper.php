<?php

declare(strict_types=1);

namespace WPStaticSecure\Fetching;

use InvalidArgumentException;

final class OutputPathMapper
{
    public function map(string $normalizedUrl): string
    {
        $parts = parse_url($normalizedUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Output mapping requires an absolute normalized URL.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Query strings and fragments are not supported for page output paths.');
        }

        $path = $parts['path'] ?? '/';
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new InvalidArgumentException('Unsafe characters are not allowed in output paths.');
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, '/') || str_contains($segment, '\\')) {
                throw new InvalidArgumentException('Unsafe path segment rejected.');
            }
        }

        if ($segments === []) {
            return 'index.html';
        }

        $last = $segments[array_key_last($segments)];
        if (str_ends_with($path, '/') || pathinfo($last, PATHINFO_EXTENSION) === '') {
            $segments[] = 'index.html';
        }

        return implode('/', $segments);
    }
}

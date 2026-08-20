<?php

declare(strict_types=1);

namespace WPStaticSecure\Configuration;

use InvalidArgumentException;

final class OutputDirectory
{
    private string $path;

    public function __construct(string $path)
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Output directory must be a non-empty absolute path.');
        }

        $normalized = str_replace('\\', '/', $path);
        $isUnixAbsolute = str_starts_with($normalized, '/') && !str_starts_with($normalized, '//');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1;
        if (!$isUnixAbsolute && !$isWindowsAbsolute) {
            throw new InvalidArgumentException('Output directory must be an absolute path.');
        }

        $segments = explode('/', $normalized);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('Output directory must not contain dot segments.');
        }

        $trimmed = rtrim($normalized, '/');
        if ($trimmed === '' || preg_match('/^[A-Za-z]:$/', $trimmed) === 1) {
            throw new InvalidArgumentException('Filesystem root cannot be used as the output directory.');
        }

        $this->path = $trimmed;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __toString(): string
    {
        return $this->path;
    }
}

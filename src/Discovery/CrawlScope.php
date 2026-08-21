<?php

declare(strict_types=1);

namespace WPStaticSecure\Discovery;

use InvalidArgumentException;
use WPStaticSecure\Configuration\Origin;

final class CrawlScope
{
    public const INTERNAL = 'internal';
    public const OUT_OF_SCOPE = 'out_of_scope';
    public const EXTERNAL = 'external';

    private string $origin;
    private string $pathPrefix;

    public function __construct(Origin $origin, string $pathPrefix = '/', ?UrlNormalizer $normalizer = null)
    {
        $normalizer ??= new UrlNormalizer();
        $this->origin = $origin->value();

        if (!str_starts_with($pathPrefix, '/') || str_contains($pathPrefix, '?') || str_contains($pathPrefix, '#')) {
            throw new InvalidArgumentException('Crawl scope path must be an absolute path without query or fragment.');
        }

        $normalized = $normalizer->normalize($this->origin . $pathPrefix);
        $parts = parse_url($normalized);
        $this->pathPrefix = rtrim((string) ($parts['path'] ?? '/'), '/');
        if ($this->pathPrefix === '') {
            $this->pathPrefix = '/';
        }
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function classify(string $normalizedUrl): string
    {
        $parts = parse_url($normalizedUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Scope classification requires a normalized absolute URL.');
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if ($origin !== $this->origin) {
            return self::EXTERNAL;
        }

        $path = $parts['path'] ?? '/';
        if ($this->pathPrefix === '/') {
            return self::INTERNAL;
        }

        if ($path === $this->pathPrefix || str_starts_with($path, $this->pathPrefix . '/')) {
            return self::INTERNAL;
        }

        return self::OUT_OF_SCOPE;
    }
}

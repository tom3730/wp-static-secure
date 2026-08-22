<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

use InvalidArgumentException;
use JsonSerializable;

/** Immutable, provider-neutral relative-path to SHA-256 manifest. */
final class FileManifest implements JsonSerializable
{
    /** @var array<string,string> */
    private array $entries;

    /** @param list<array{path:string,sha256:string}> $entries */
    private function __construct(array $entries)
    {
        $map = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['sha256']) || !is_string($entry['path']) || !is_string($entry['sha256'])) {
                throw new InvalidArgumentException('Manifest entries must contain a path and SHA-256 digest.');
            }
            $path = self::safePath($entry['path']);
            if (array_key_exists($path, $map)) {
                throw new InvalidArgumentException('Manifest contains duplicate paths.');
            }
            $map[$path] = self::hash($entry['sha256']);
        }
        ksort($map, SORT_STRING);
        $this->entries = $map;
    }

    /** @param list<array{path:string,sha256:string}> $entries */
    public static function fromEntries(array $entries): self
    {
        return new self($entries);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @return list<array{path:string,sha256:string}> */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->entries as $path => $sha256) {
            $entries[] = ['path' => $path, 'sha256' => $sha256];
        }
        return $entries;
    }

    /** @return array<string,string> */
    public function asMap(): array
    {
        return $this->entries;
    }

    public function hashFor(string $path): ?string
    {
        return $this->entries[$path] ?? null;
    }

    public function equals(self $other): bool
    {
        return $this->entries === $other->entries;
    }

    public function jsonSerialize(): array
    {
        return $this->entries();
    }

    private static function safePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new InvalidArgumentException('Manifest path must be a safe relative path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Manifest path traversal rejected.');
            }
        }
        return $path;
    }

    private static function hash(string $hash): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new InvalidArgumentException('Manifest hash must be a lowercase SHA-256 digest.');
        }
        return $hash;
    }
}

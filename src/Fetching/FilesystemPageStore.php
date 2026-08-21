<?php

declare(strict_types=1);

namespace WPStaticSecure\Fetching;

use InvalidArgumentException;
use RuntimeException;

final class FilesystemPageStore
{
    private string $root;

    public function __construct(string $outputDirectory)
    {
        if ($outputDirectory === '') {
            throw new InvalidArgumentException('Output directory must not be empty.');
        }
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('Unable to create output directory.');
        }
        $root = realpath($outputDirectory);
        if ($root === false) {
            throw new RuntimeException('Unable to resolve output directory.');
        }
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function write(string $relativePath, string $body): void
    {
        $this->assertSafeRelativePath($relativePath);
        $parts = explode('/', $relativePath);
        $filename = array_pop($parts);
        $directory = $this->root;

        foreach ($parts as $part) {
            $directory .= DIRECTORY_SEPARATOR . $part;
            if (is_link($directory)) {
                throw new RuntimeException('Refusing to write through a symbolic link in the output directory.');
            }
            if (!is_dir($directory) && !mkdir($directory, 0775) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create output subdirectory.');
            }
        }

        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_link($target)) {
            throw new RuntimeException('Refusing to overwrite a symbolic link.');
        }
        if (file_put_contents($target, $body, LOCK_EX) === false) {
            throw new RuntimeException('Unable to persist static page.');
        }
    }

    /** @param list<array<string, mixed>> $entries */
    public function writeManifest(array $entries): void
    {
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['output_path'], (string) $b['output_path']));
        $json = json_encode(['pages' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->write('.wp-static-secure/manifest.json', $json);
    }

    private function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Output path must be a safe relative path.');
        }
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Output path traversal rejected.');
            }
        }
    }
}

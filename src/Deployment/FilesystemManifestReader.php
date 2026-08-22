<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Filesystem-only boundary for producing a provider-neutral file manifest. */
final class FilesystemManifestReader
{
    public function read(string $root): FileManifest
    {
        $canonical = $this->canonicalDirectory($root, 'Manifest root');
        $entries = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($canonical, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $pathname = str_replace('\\', '/', $file->getPathname());
            if ($file->isLink()) {
                throw new InvalidArgumentException('Manifest root contains a symbolic link.');
            }
            if (!$file->isFile() || !$file->isReadable()) {
                throw new InvalidArgumentException('Manifest root contains a non-regular or unreadable entry.');
            }
            $relative = ltrim(substr($pathname, strlen($canonical)), '/');
            $hash = hash_file('sha256', $pathname);
            if ($hash === false) {
                throw new InvalidArgumentException('Manifest file could not be hashed.');
            }
            $entries[] = ['path' => $relative, 'sha256' => $hash];
        }
        return FileManifest::fromEntries($entries);
    }

    public function canonicalDirectory(string $path, string $label = 'Directory'): string
    {
        $normalized = str_replace('\\', '/', $path);
        $isUnixAbsolute = str_starts_with($normalized, '/') && !str_starts_with($normalized, '//');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1;
        if (!$isUnixAbsolute && !$isWindowsAbsolute) {
            throw new InvalidArgumentException($label . ' must be an absolute path.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $normalized) === 1) {
            throw new InvalidArgumentException($label . ' contains an unsafe character.');
        }
        $segments = explode('/', $normalized);
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException($label . ' must not contain dot segments.');
        }
        $this->assertNoSymlinkComponents($normalized, $label);
        $withoutTrailingSeparator = rtrim($path, '/\\');
        if (is_link($withoutTrailingSeparator)) {
            throw new InvalidArgumentException($label . ' must not be a symbolic link.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException($label . ' must be an existing directory.');
        }
        $canonical = rtrim(str_replace('\\', '/', $resolved), '/');
        if ($canonical === '' || preg_match('/^[A-Za-z]:$/', $canonical) === 1) {
            throw new InvalidArgumentException('Filesystem root cannot be used as ' . strtolower($label) . '.');
        }
        return $canonical;
    }

    private function assertNoSymlinkComponents(string $normalized, string $label): void
    {
        $current = str_starts_with($normalized, '/') ? '/' : substr($normalized, 0, 3);
        $parts = explode('/', ltrim(substr($normalized, strlen($current)), '/'));
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current = rtrim($current, '/') . '/' . $part;
            if (is_link($current)) {
                throw new InvalidArgumentException($label . ' must not traverse a symbolic link.');
            }
        }
    }

    public function assertDisjoint(string $left, string $right): void
    {
        $left = $this->canonicalDirectory($left, 'Build root');
        $right = $this->canonicalDirectory($right, 'Target root');
        if ($left === $right || str_starts_with($left, $right . '/') || str_starts_with($right, $left . '/')) {
            throw new InvalidArgumentException('Build and target roots must not overlap.');
        }
    }
}

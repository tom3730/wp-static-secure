<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

use InvalidArgumentException;

final class DeploymentOperation
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const KEEP = 'keep';
    public const DELETE = 'delete';

    private function __construct(private string $action, private string $path, private ?string $sourceSha256, private ?string $targetSha256)
    {
    }

    public static function create(string $path, string $sourceSha256): self
    {
        return new self(self::CREATE, self::safePath($path), self::hash($sourceSha256), null);
    }

    public static function update(string $path, string $sourceSha256, string $targetSha256): self
    {
        $sourceSha256 = self::hash($sourceSha256);
        $targetSha256 = self::hash($targetSha256);
        if ($sourceSha256 === $targetSha256) {
            throw new InvalidArgumentException('Update operation requires different source and target hashes.');
        }
        return new self(self::UPDATE, self::safePath($path), $sourceSha256, $targetSha256);
    }

    public static function keep(string $path, string $sha256): self
    {
        $sha256 = self::hash($sha256);
        return new self(self::KEEP, self::safePath($path), $sha256, $sha256);
    }

    public static function delete(string $path, string $targetSha256): self
    {
        return new self(self::DELETE, self::safePath($path), null, self::hash($targetSha256));
    }

    public function action(): string
    {
        return $this->action;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function sourceSha256(): ?string
    {
        return $this->sourceSha256;
    }

    public function targetSha256(): ?string
    {
        return $this->targetSha256;
    }

    /** @return array{action:string,path:string,source_sha256:?string,target_sha256:?string} */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'path' => $this->path,
            'source_sha256' => $this->sourceSha256,
            'target_sha256' => $this->targetSha256,
        ];
    }

    private static function safePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new InvalidArgumentException('Deployment operation path must be a safe relative path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Deployment operation path traversal rejected.');
            }
        }
        return $path;
    }

    private static function hash(string $hash): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new InvalidArgumentException('Deployment operation hash must be a lowercase SHA-256 digest.');
        }
        return $hash;
    }
}

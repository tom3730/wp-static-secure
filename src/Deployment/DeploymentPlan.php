<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

use InvalidArgumentException;
use JsonSerializable;

final class DeploymentPlan implements JsonSerializable
{
    /** @param list<DeploymentOperation> $operations */
    private function __construct(private ValidatedBuild $build, private DeploymentTarget $target, private DeploymentInventory $targetInventory, private array $operations)
    {
    }

    /** @param list<DeploymentOperation> $operations */
    public static function create(ValidatedBuild $build, DeploymentTarget $target, DeploymentInventory $targetInventory, array $operations): self
    {
        if ($target->fingerprint() !== $targetInventory->target()->fingerprint()) {
            throw new InvalidArgumentException('Deployment plan target does not match target inventory.');
        }
        $previousPath = null;
        foreach ($operations as $operation) {
            if (!$operation instanceof DeploymentOperation) {
                throw new InvalidArgumentException('Deployment plan contains an invalid operation.');
            }
            if ($previousPath !== null && strcmp($previousPath, $operation->path()) >= 0) {
                throw new InvalidArgumentException('Deployment plan operations must be strictly sorted and unique.');
            }
            $previousPath = $operation->path();
        }
        $source = $build->manifest()->asMap();
        $existing = $targetInventory->manifest()->asMap();
        $paths = array_values(array_unique(array_merge(array_keys($source), array_keys($existing))));
        sort($paths, SORT_STRING);
        if (count($paths) !== count($operations)) {
            throw new InvalidArgumentException('Deployment plan must contain exactly one operation per manifest path.');
        }
        foreach ($paths as $index => $path) {
            $sourceHash = $source[$path] ?? null;
            $targetHash = $existing[$path] ?? null;
            $expected = $sourceHash !== null && $targetHash === null
                ? DeploymentOperation::create($path, $sourceHash)
                : ($sourceHash === null && $targetHash !== null
                    ? DeploymentOperation::delete($path, $targetHash)
                    : ($sourceHash === $targetHash
                        ? DeploymentOperation::keep($path, $sourceHash)
                        : DeploymentOperation::update($path, $sourceHash, $targetHash)));
            if ($operations[$index]->toArray() !== $expected->toArray()) {
                throw new InvalidArgumentException('Deployment plan operation does not match its source and target manifests.');
            }
        }
        return new self($build, $target, $targetInventory, array_values($operations));
    }

    public function build(): ValidatedBuild
    {
        return $this->build;
    }

    public function target(): DeploymentTarget
    {
        return $this->target;
    }

    public function targetInventory(): DeploymentInventory
    {
        return $this->targetInventory;
    }

    /** @return list<DeploymentOperation> */
    public function operations(): array
    {
        return $this->operations;
    }

    /** @return list<DeploymentOperation> */
    public function operationsFor(string $action): array
    {
        return array_values(array_filter($this->operations, static fn (DeploymentOperation $operation): bool => $operation->action() === $action));
    }

    public function isDestructive(): bool
    {
        return $this->operationsFor(DeploymentOperation::DELETE) !== [];
    }

    /** @return array{target:array{identity:string,root_identity:string},build_root:string,operations:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'target' => ['identity' => $this->target->identity(), 'root_identity' => $this->target->rootIdentity()],
            'build_root' => $this->build->root(),
            'operations' => array_map(static fn (DeploymentOperation $operation): array => $operation->toArray(), $this->operations),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

/** Filesystem adapter that snapshots a target without exposing its path to the planner. */
final class FilesystemDeploymentInventoryReader
{
    public function __construct(private ?FilesystemManifestReader $reader = null)
    {
        $this->reader ??= new FilesystemManifestReader();
    }

    public function read(DeploymentTarget $target, string $targetRoot, ValidatedBuild $build): DeploymentInventory
    {
        $this->reader->assertDisjoint($build->root(), $targetRoot);
        return new DeploymentInventory($target, $this->reader->read($targetRoot));
    }
}

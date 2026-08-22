<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

/** Immutable provider-neutral inventory returned by a trusted target adapter. */
final class DeploymentInventory
{
    public function __construct(private DeploymentTarget $target, private FileManifest $manifest)
    {
    }

    public function target(): DeploymentTarget
    {
        return $this->target;
    }

    public function manifest(): FileManifest
    {
        return $this->manifest;
    }
}

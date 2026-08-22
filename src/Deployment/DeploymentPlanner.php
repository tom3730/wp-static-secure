<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

use InvalidArgumentException;

/** Compares immutable provider-neutral manifests without transport side effects. */
final class DeploymentPlanner
{
    public function plan(ValidatedBuild $build, DeploymentTarget $target, DeploymentInventory $targetInventory): DeploymentPlan
    {
        if ($target->fingerprint() !== $targetInventory->target()->fingerprint()) {
            throw new InvalidArgumentException('Deployment target identity or root does not match target inventory.');
        }
        $build->assertCurrent();
        $source = $build->manifest()->asMap();
        $existing = $targetInventory->manifest()->asMap();
        $paths = array_values(array_unique(array_merge(array_keys($source), array_keys($existing))));
        sort($paths, SORT_STRING);
        $operations = [];
        foreach ($paths as $path) {
            $sourceHash = $source[$path] ?? null;
            $targetHash = $existing[$path] ?? null;
            if ($sourceHash !== null && $targetHash === null) {
                $operations[] = DeploymentOperation::create($path, $sourceHash);
            } elseif ($sourceHash === null && $targetHash !== null) {
                $operations[] = DeploymentOperation::delete($path, $targetHash);
            } elseif ($sourceHash === $targetHash) {
                $operations[] = DeploymentOperation::keep($path, $sourceHash);
            } else {
                $operations[] = DeploymentOperation::update($path, $sourceHash, $targetHash);
            }
        }
        $build->assertCurrent();
        return DeploymentPlan::create($build, $target, $targetInventory, $operations);
    }
}

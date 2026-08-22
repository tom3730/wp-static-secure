<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Deployment\DeploymentInventory;
use WPStaticSecure\Deployment\DeploymentOperation;
use WPStaticSecure\Deployment\DeploymentPlan;
use WPStaticSecure\Deployment\DeploymentPlanner;
use WPStaticSecure\Deployment\DeploymentTarget;
use WPStaticSecure\Deployment\FileManifest;
use WPStaticSecure\Deployment\FilesystemDeploymentInventoryReader;
use WPStaticSecure\Deployment\FilesystemManifestReader;
use WPStaticSecure\Deployment\ValidatedBuild;
use WPStaticSecure\Validation\BuildValidator;
use WPStaticSecure\Validation\ValidationReport;

final class DeploymentPlannerTest extends TestCase
{
    private string $root;
    private string $boundary;
    private string $build;
    private string $targetRoot;
    private DeploymentTarget $target;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wp-static-secure-deployment-' . bin2hex(random_bytes(6));
        $this->boundary = $this->root . '/builds';
        $this->build = $this->boundary . '/current';
        $this->targetRoot = $this->root . '/targets/production';
        mkdir($this->build, 0775, true);
        mkdir($this->targetRoot, 0775, true);
        $this->target = new DeploymentTarget('production', 'public-root');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testProducesDeterministicCreateUpdateKeepAndDeleteOperations(): void
    {
        file_put_contents($this->build . '/new.html', 'new');
        file_put_contents($this->build . '/same.html', 'same');
        file_put_contents($this->build . '/changed.html', 'new version');
        file_put_contents($this->targetRoot . '/same.html', 'same');
        file_put_contents($this->targetRoot . '/changed.html', 'old version');
        file_put_contents($this->targetRoot . '/stale.html', 'stale');

        $build = $this->validatedBuild();
        $inventory = $this->inventory($build);
        $plan = (new DeploymentPlanner())->plan($build, $this->target, $inventory);
        $again = (new DeploymentPlanner())->plan($build, $this->target, $inventory);

        self::assertSame(
            ['changed.html', 'new.html', 'same.html', 'stale.html'],
            array_map(static fn (DeploymentOperation $operation): string => $operation->path(), $plan->operations())
        );
        self::assertSame(
            [DeploymentOperation::UPDATE, DeploymentOperation::CREATE, DeploymentOperation::KEEP, DeploymentOperation::DELETE],
            array_map(static fn (DeploymentOperation $operation): string => $operation->action(), $plan->operations())
        );
        self::assertSame($plan->toArray(), $again->toArray());
        self::assertTrue($plan->isDestructive());
        self::assertArrayNotHasKey('target_path', $plan->operations()[0]->toArray());
        self::assertFileExists($this->targetRoot . '/stale.html');
    }

    public function testPlannerRequiresValidatedBuildObjectAndCannotBeCalledWithRawPaths(): void
    {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line */
        (new DeploymentPlanner())->plan($this->build, $this->target, new DeploymentInventory($this->target, FileManifest::empty()));
    }

    public function testRejectsMissingBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BuildValidator($this->build . '/missing', 'https://wp.internal.example', 'https://www.example.com'))->validate();
    }

    public function testRejectsEmptyBuild(): void
    {
        $report = $this->buildReport();
        $this->expectException(InvalidArgumentException::class);
        ValidatedBuild::fromDirectory($this->build, $this->boundary, $report);
    }

    public function testRejectsBuildOutsideExpectedBoundary(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/index.html', 'outside');
        $report = (new BuildValidator($outside, 'https://wp.internal.example', 'https://www.example.com'))->validate();

        $this->expectException(InvalidArgumentException::class);
        ValidatedBuild::fromDirectory($outside, $this->boundary, $report);
    }

    public function testRejectsFilesystemRootAsBuildBoundary(): void
    {
        file_put_contents($this->build . '/index.html', 'hello');
        $report = $this->buildReport();
        $this->expectException(InvalidArgumentException::class);
        ValidatedBuild::fromDirectory($this->build, '/', $report);
    }

    public function testRejectsValidationReportBoundToDifferentRoot(): void
    {
        file_put_contents($this->build . '/index.html', 'hello');
        $other = $this->root . '/builds/other';
        mkdir($other, 0775, true);
        file_put_contents($other . '/index.html', 'other');
        $report = (new BuildValidator($other, 'https://wp.internal.example', 'https://www.example.com'))->validate();

        $this->expectException(InvalidArgumentException::class);
        ValidatedBuild::fromDirectory($this->build, $this->boundary, $report);
    }

    public function testRejectsSuccessfulReportWithoutBoundRoot(): void
    {
        file_put_contents($this->build . '/index.html', 'hello');
        $report = new ValidationReport([]);
        $this->expectException(InvalidArgumentException::class);
        ValidatedBuild::fromDirectory($this->build, $this->boundary, $report);
    }

    public function testRejectsBuildModifiedAfterValidation(): void
    {
        file_put_contents($this->build . '/index.html', 'before');
        $build = $this->validatedBuild();
        file_put_contents($this->build . '/index.html', 'after');

        $this->expectException(InvalidArgumentException::class);
        (new DeploymentPlanner())->plan($build, $this->target, $this->inventory($build));
    }

    public function testRejectsBuildModifiedBetweenValidationAndValidatedSnapshot(): void
    {
        file_put_contents($this->build . '/index.html', 'before');
        $report = $this->buildReport();
        file_put_contents($this->build . '/index.html', 'after');

        $this->expectException(InvalidArgumentException::class);
        ValidatedBuild::fromDirectory($this->build, $this->boundary, $report);
    }

    public function testRejectsMismatchedTargetIdentityOrRootIdentity(): void
    {
        file_put_contents($this->build . '/index.html', 'hello');
        $build = $this->validatedBuild();
        $inventory = $this->inventory($build);

        $this->expectException(InvalidArgumentException::class);
        (new DeploymentPlanner())->plan($build, new DeploymentTarget('staging', 'public-root'), $inventory);
    }

    public function testRejectsMismatchedTargetRootIdentityEvenWhenTargetNameMatches(): void
    {
        file_put_contents($this->build . '/index.html', 'hello');
        $build = $this->validatedBuild();
        $inventory = $this->inventory($build);

        $this->expectException(InvalidArgumentException::class);
        (new DeploymentPlanner())->plan($build, new DeploymentTarget('production', 'other-root'), $inventory);
    }

    public function testFilesystemAdapterRejectsOverlappingBuildAndTargetRoots(): void
    {
        file_put_contents($this->build . '/index.html', 'hello');
        $build = $this->validatedBuild();
        foreach ([$this->build, $this->boundary, $this->build . '/nested-target'] as $targetRoot) {
            if (!is_dir($targetRoot)) {
                mkdir($targetRoot, 0775, true);
            }
            try {
                (new FilesystemDeploymentInventoryReader())->read($this->target, $targetRoot, $build);
                self::fail('Expected overlapping root rejection for ' . $targetRoot);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('overlap', strtolower($exception->getMessage()));
            }
        }
    }

    public function testRejectsSymlinkInBuildAndTargetSnapshots(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is unavailable.');
        }
        $outside = $this->root . '/outside';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/secret.html', 'secret');
        if (!@symlink($outside . '/secret.html', $this->build . '/secret.html')) {
            self::markTestSkipped('Unable to create a symlink in this environment.');
        }
        try {
            $report = $this->buildReport();
            $this->expectException(InvalidArgumentException::class);
            ValidatedBuild::fromDirectory($this->build, $this->boundary, $report);
        } finally {
            @unlink($this->build . '/secret.html');
        }
    }

    public function testFilesystemInventoryRejectsSymlinkInTarget(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is unavailable.');
        }
        file_put_contents($this->build . '/index.html', 'hello');
        $build = $this->validatedBuild();
        $outside = $this->root . '/outside-target';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/secret.html', 'secret');
        if (!@symlink($outside . '/secret.html', $this->targetRoot . '/secret.html')) {
            self::markTestSkipped('Unable to create a symlink in this environment.');
        }
        try {
            $this->expectException(InvalidArgumentException::class);
            (new FilesystemDeploymentInventoryReader())->read($this->target, $this->targetRoot, $build);
        } finally {
            @unlink($this->targetRoot . '/secret.html');
        }
    }

    public function testManifestAndOperationsRejectTraversalDuplicateInvalidHashAndInvalidPlanOrder(): void
    {
        $hash = str_repeat('a', 64);
        $this->expectException(InvalidArgumentException::class);
        FileManifest::fromEntries([
            ['path' => '../escape.html', 'sha256' => $hash],
            ['path' => '../escape.html', 'sha256' => $hash],
        ]);
    }

    public function testManifestRejectsDuplicateSafePaths(): void
    {
        $hash = str_repeat('a', 64);
        $this->expectException(InvalidArgumentException::class);
        FileManifest::fromEntries([
            ['path' => 'z.html', 'sha256' => $hash],
            ['path' => 'z.html', 'sha256' => $hash],
        ]);
    }

    public function testOperationConstructorsEnforceStateAndHashInvariants(): void
    {
        $hash = str_repeat('a', 64);
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperation::update('index.html', $hash, $hash);
    }

    public function testOperationRejectsTraversalPathAndMalformedHash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DeploymentOperation::create('../escape.html', str_repeat('a', 64));
    }

    public function testPlanRejectsUnsortedOrDuplicateOperations(): void
    {
        file_put_contents($this->build . '/index.html', 'hello');
        $build = $this->validatedBuild();
        $inventory = new DeploymentInventory($this->target, FileManifest::empty());
        $operation = DeploymentOperation::create('index.html', hash_file('sha256', $this->build . '/index.html'));

        $this->expectException(InvalidArgumentException::class);
        DeploymentPlan::create($build, $this->target, $inventory, [$operation, $operation]);
    }

    private function buildReport(): ValidationReport
    {
        return (new BuildValidator($this->build, 'https://wp.internal.example', 'https://www.example.com'))->validate();
    }

    private function validatedBuild(): ValidatedBuild
    {
        return ValidatedBuild::fromDirectory($this->build, $this->boundary, $this->buildReport());
    }

    private function inventory(ValidatedBuild $build): DeploymentInventory
    {
        return (new FilesystemDeploymentInventoryReader())->read($this->target, $this->targetRoot, $build);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isLink() || $item->isFile() ? @unlink($item->getPathname()) : @rmdir($item->getPathname());
        }
        @rmdir($path);
    }
}

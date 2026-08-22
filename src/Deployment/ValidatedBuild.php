<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

use InvalidArgumentException;
use WPStaticSecure\Validation\ValidationReport;

/** A successful build validation bound to one canonical root and exact hashes. */
final class ValidatedBuild
{
    private function __construct(private string $root, private FileManifest $manifest)
    {
    }

    public static function fromDirectory(string $root, string $expectedBoundary, ValidationReport $report): self
    {
        if (!$report->isSuccessful()) {
            throw new InvalidArgumentException('Deployment requires a successful build validation report.');
        }
        $reader = new FilesystemManifestReader();
        $canonicalRoot = $reader->canonicalDirectory($root, 'Build root');
        $canonicalBoundary = $reader->canonicalDirectory($expectedBoundary, 'Expected build boundary');
        if ($canonicalRoot !== $canonicalBoundary && !str_starts_with($canonicalRoot, $canonicalBoundary . '/')) {
            throw new InvalidArgumentException('Build root is outside the expected output boundary.');
        }
        if ($report->outputDirectory() !== $canonicalRoot) {
            throw new InvalidArgumentException('Build validation report is bound to a different root.');
        }
        $manifest = $reader->read($canonicalRoot);
        $reportEntries = $report->snapshotEntries();
        if ($reportEntries === null) {
            throw new InvalidArgumentException('Build validation report has no bound file snapshot.');
        }
        $reportManifest = FileManifest::fromEntries(array_map(
            static fn (string $path, string $sha256): array => ['path' => $path, 'sha256' => $sha256],
            array_keys($reportEntries),
            array_values($reportEntries)
        ));
        if (!$manifest->equals($reportManifest)) {
            throw new InvalidArgumentException('Build changed between validation and snapshot capture.');
        }
        if ($manifest->entries() === []) {
            throw new InvalidArgumentException('Validated build must contain at least one regular file.');
        }
        return new self($canonicalRoot, $manifest);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function manifest(): FileManifest
    {
        return $this->manifest;
    }

    public function assertCurrent(): void
    {
        $current = (new FilesystemManifestReader())->read($this->root);
        if (!$current->equals($this->manifest)) {
            throw new InvalidArgumentException('Local build changed after validation; deployment plan aborted.');
        }
    }
}

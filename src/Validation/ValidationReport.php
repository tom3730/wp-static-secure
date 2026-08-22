<?php

declare(strict_types=1);

namespace WPStaticSecure\Validation;

final class ValidationReport
{
    /** @param list<array{severity:string,type:string,file:string,reference?:string,message:string}> $issues */
    /** @param ?array<string,string> $snapshotEntries */
    public function __construct(private array $issues, private ?string $outputDirectory = null, private ?array $snapshotEntries = null)
    {
    }

    /** @return list<array{severity:string,type:string,file:string,reference?:string,message:string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function outputDirectory(): ?string
    {
        return $this->outputDirectory;
    }

    /** @return ?array<string,string> */
    public function snapshotEntries(): ?array
    {
        return $this->snapshotEntries;
    }

    public function isSuccessful(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'error') {
                return false;
            }
        }

        return true;
    }

    public function errorCount(): int
    {
        return count(array_filter($this->issues, static fn (array $issue): bool => $issue['severity'] === 'error'));
    }

    public function warningCount(): int
    {
        return count(array_filter($this->issues, static fn (array $issue): bool => $issue['severity'] === 'warning'));
    }
}

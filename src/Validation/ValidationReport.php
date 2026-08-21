<?php

declare(strict_types=1);

namespace WPStaticSecure\Validation;

final class ValidationReport
{
    /** @param list<array{severity:string,type:string,file:string,reference?:string,message:string}> $issues */
    public function __construct(private array $issues)
    {
    }

    /** @return list<array{severity:string,type:string,file:string,reference?:string,message:string}> */
    public function issues(): array
    {
        return $this->issues;
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

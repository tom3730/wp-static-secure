<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use InvalidArgumentException;

final class StoredSubmission
{
    /** @param array<string, string> $fields */
    public function __construct(
        private int $id,
        private string $formId,
        private array $fields,
        private string $status,
        private string $createdAt
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('Stored submission id must be positive.');
        }
        if (!SubmissionStatus::isValid($status)) {
            throw new InvalidArgumentException('Stored submission status is invalid.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function formId(): string
    {
        return $this->formId;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}

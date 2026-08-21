<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use InvalidArgumentException;

final class FormDefinition
{
    /** @var list<string> */
    private array $allowedFields;

    /** @param list<string> $allowedFields */
    public function __construct(private string $formId, array $allowedFields)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $formId) !== 1) {
            throw new InvalidArgumentException('Form identifier must match ^[a-z][a-z0-9_-]{0,63}$.');
        }

        $normalized = [];
        foreach ($allowedFields as $field) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $field) !== 1) {
                throw new InvalidArgumentException('Form field names must use letters, digits, dot, underscore, or hyphen.');
            }
            $normalized[$field] = true;
        }
        $this->allowedFields = array_keys($normalized);
        sort($this->allowedFields, SORT_STRING);
    }

    public function formId(): string
    {
        return $this->formId;
    }

    /** @return list<string> */
    public function allowedFields(): array
    {
        return $this->allowedFields;
    }

    public function allowsField(string $field): bool
    {
        return in_array($field, $this->allowedFields, true);
    }
}

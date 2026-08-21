<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

final class Submission
{
    /** @param array<string, string> $fields */
    public function __construct(private string $formId, private array $fields)
    {
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

    /** @return array{form_id:string,fields:array<string,string>} */
    public function toArray(): array
    {
        return ['form_id' => $this->formId, 'fields' => $this->fields];
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use DOMElement;

interface FormAdapter
{
    public function supports(DOMElement $form): bool;

    public function extractSchema(DOMElement $form): FormDefinition;

    public function rewrite(DOMElement $form, FormDefinition $definition, string $submissionEndpoint): void;

    /** @param array<string, mixed> $payload */
    public function validateSubmission(FormDefinition $definition, array $payload): Submission;
}

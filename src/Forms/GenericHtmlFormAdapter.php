<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use DOMElement;
use InvalidArgumentException;

final class GenericHtmlFormAdapter implements FormAdapter
{
    public const FORM_ATTRIBUTE = 'data-wpss-form';
    public const FORM_ID_FIELD = '_wpss_form_id';

    public function supports(DOMElement $form): bool
    {
        return $form->hasAttribute(self::FORM_ATTRIBUTE);
    }

    public function extractSchema(DOMElement $form): FormDefinition
    {
        if (!$this->supports($form)) {
            throw new InvalidArgumentException('Generic HTML adapter only supports explicitly marked forms.');
        }

        $fields = [];
        foreach ($form->getElementsByTagName('*') as $control) {
            if (!$control instanceof DOMElement || !$control->hasAttribute('name') || $control->hasAttribute('disabled')) {
                continue;
            }

            $tag = strtolower($control->tagName);
            if (!in_array($tag, ['input', 'select', 'textarea'], true)) {
                continue;
            }
            if ($tag === 'input') {
                $type = strtolower($control->getAttribute('type') ?: 'text');
                if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                    continue;
                }
            }

            $name = trim($control->getAttribute('name'));
            if ($name === '' || $name === self::FORM_ID_FIELD) {
                continue;
            }
            $fields[] = $name;
        }

        return new FormDefinition(trim($form->getAttribute(self::FORM_ATTRIBUTE)), $fields);
    }

    public function rewrite(DOMElement $form, FormDefinition $definition, string $submissionEndpoint): void
    {
        if (!$this->isAbsoluteHttpUrl($submissionEndpoint)) {
            throw new InvalidArgumentException('Submission endpoint must be an absolute HTTP(S) URL.');
        }

        $form->setAttribute('action', $submissionEndpoint);
        $form->setAttribute('method', 'post');
        $form->setAttribute('accept-charset', 'UTF-8');

        $document = $form->ownerDocument;
        if ($document === null) {
            throw new InvalidArgumentException('Form must belong to a document before rewriting.');
        }

        foreach ($form->getElementsByTagName('input') as $input) {
            if ($input instanceof DOMElement && $input->getAttribute('name') === self::FORM_ID_FIELD) {
                $input->setAttribute('type', 'hidden');
                $input->setAttribute('value', $definition->formId());
                return;
            }
        }

        $hidden = $document->createElement('input');
        $hidden->setAttribute('type', 'hidden');
        $hidden->setAttribute('name', self::FORM_ID_FIELD);
        $hidden->setAttribute('value', $definition->formId());
        $form->insertBefore($hidden, $form->firstChild);
    }

    public function validateSubmission(FormDefinition $definition, array $payload): Submission
    {
        $formId = $payload[self::FORM_ID_FIELD] ?? null;
        if (!is_string($formId) || $formId !== $definition->formId()) {
            throw new SubmissionValidationException('Submission form identifier is missing or invalid.');
        }

        $fields = [];
        foreach ($payload as $name => $value) {
            if ($name === self::FORM_ID_FIELD) {
                continue;
            }
            if (!$definition->allowsField((string) $name)) {
                throw new SubmissionValidationException('Submission contains a field that is not allowed for this form.');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new SubmissionValidationException('Submission field values must be scalar.');
            }
            $fields[(string) $name] = $value === null ? '' : (string) $value;
        }

        ksort($fields, SORT_STRING);
        return new Submission($definition->formId(), $fields);
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && $parts['host'] !== ''
            && !isset($parts['user'], $parts['pass']);
    }
}

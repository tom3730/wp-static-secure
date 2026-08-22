<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Adapts the scalar, server-rendered subset of Contact Form 7 forms.
 *
 * This adapter deliberately does not implement CF7's REST/AJAX protocol. The
 * rendered controls are rewritten to the WP Static Secure submission boundary.
 */
final class ContactForm7Adapter implements FormAdapter
{
    public const FORM_ATTRIBUTE = GenericHtmlFormAdapter::FORM_ATTRIBUTE;
    public const FORM_ID_FIELD = GenericHtmlFormAdapter::FORM_ID_FIELD;

    private const CF7_FORM_CLASS = 'wpcf7-form';
    private const CF7_ID_FIELD = '_wpcf7';
    private const CF7_VERSION_FIELD = '_wpcf7_version';
    private const CF7_VERSION_PATTERN = '/^[56]\.[0-9]+\.[0-9]+$/';
    private const PRIVATE_FIELD_PATTERN = '/^_(?:wpcf7(?:_|$)|wpnonce$|wp_http_referer$)/';

    public function supports(DOMElement $form): bool
    {
        if ($form->hasAttribute(self::FORM_ATTRIBUTE) || !$this->hasClass($form, self::CF7_FORM_CLASS)) {
            return false;
        }

        $cf7Id = $this->singleHiddenFieldValue($form, self::CF7_ID_FIELD);
        $cf7Version = $this->singleHiddenFieldValue($form, self::CF7_VERSION_FIELD);
        return $cf7Id !== null
            && preg_match('/^[1-9][0-9]{0,31}$/', $cf7Id) === 1
            && $cf7Version !== null
            && preg_match(self::CF7_VERSION_PATTERN, $cf7Version) === 1;
    }

    public function extractSchema(DOMElement $form): FormDefinition
    {
        if (!$this->supports($form)) {
            throw new InvalidArgumentException('Contact Form 7 adapter only supports recognized rendered forms.');
        }

        $cf7Id = $this->singleHiddenFieldValue($form, self::CF7_ID_FIELD);
        if ($cf7Id === null) {
            throw new InvalidArgumentException('Contact Form 7 form identifier is missing.');
        }

        $fields = [];
        foreach ($form->getElementsByTagName('*') as $control) {
            if (!$control instanceof DOMElement || !$control->hasAttribute('name')) {
                continue;
            }

            $name = trim($control->getAttribute('name'));
            if ($name === '' || $name === self::FORM_ID_FIELD || $this->isPrivateField($name)) {
                continue;
            }

            $tag = strtolower($control->tagName);
            if (!in_array($tag, ['input', 'select', 'textarea'], true)) {
                continue;
            }
            if ($control->hasAttribute('disabled')) {
                continue;
            }

            if ($tag === 'input') {
                $type = strtolower($control->getAttribute('type') ?: 'text');
                if ($type === 'hidden') {
                    throw new InvalidArgumentException('Contact Form 7 user-defined hidden fields are unsupported.');
                }
                if (in_array($type, ['submit', 'button', 'reset', 'image'], true)) {
                    continue;
                }
                if ($type === 'file') {
                    throw new InvalidArgumentException('Contact Form 7 file fields are unsupported.');
                }
            }

            if ($tag === 'select' && $control->hasAttribute('multiple')) {
                throw new InvalidArgumentException('Contact Form 7 multi-select fields are unsupported.');
            }
            if (str_ends_with($name, '[]')) {
                throw new InvalidArgumentException('Contact Form 7 array-valued fields are unsupported.');
            }

            $fields[] = $name;
        }

        if ($fields === []) {
            throw new InvalidArgumentException('Contact Form 7 form has no supported scalar fields.');
        }

        return new FormDefinition('cf7-' . $cf7Id, $fields);
    }

    public function rewrite(DOMElement $form, FormDefinition $definition, string $submissionEndpoint): void
    {
        if (!$this->isAbsoluteHttpUrl($submissionEndpoint)) {
            throw new InvalidArgumentException('Submission endpoint must be an absolute HTTP(S) URL.');
        }

        $form->setAttribute(self::FORM_ATTRIBUTE, $definition->formId());
        $form->setAttribute('action', $submissionEndpoint);
        $form->setAttribute('method', 'post');
        $form->setAttribute('accept-charset', 'UTF-8');
        $form->removeAttribute('novalidate');

        // CF7's JavaScript uses this class to send its own REST/AJAX request.
        // Remove the hook so the browser submits only to the explicit endpoint.
        $classes = preg_split('/\s+/', trim($form->getAttribute('class'))) ?: [];
        $classes = array_values(array_filter($classes, static fn (string $class): bool => $class !== self::CF7_FORM_CLASS));
        if ($classes === []) {
            $form->removeAttribute('class');
        } else {
            $form->setAttribute('class', implode(' ', $classes));
        }
        foreach (iterator_to_array($form->attributes) as $attribute) {
            if ($attribute->name === 'data-status' || str_starts_with(strtolower($attribute->name), 'data-wpcf7-')) {
                $form->removeAttribute($attribute->name);
            }
        }
        $container = $form->parentNode instanceof DOMElement ? $form->parentNode : null;
        if ($container !== null && $this->hasClass($container, 'wpcf7')) {
            $containerClasses = preg_split('/\s+/', trim($container->getAttribute('class'))) ?: [];
            $containerClasses = array_values(array_filter($containerClasses, static fn (string $class): bool => $class !== 'wpcf7'));
            if ($containerClasses === []) {
                $container->removeAttribute('class');
            } else {
                $container->setAttribute('class', implode(' ', $containerClasses));
            }
            foreach (iterator_to_array($container->attributes) as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'data-wpcf7-')) {
                    $container->removeAttribute($attribute->name);
                }
            }
        }

        $document = $form->ownerDocument;
        if (!$document instanceof DOMDocument) {
            throw new InvalidArgumentException('Form must belong to a document before rewriting.');
        }

        foreach (iterator_to_array($form->getElementsByTagName('*')) as $control) {
            if (!$control instanceof DOMElement) {
                continue;
            }
            $name = trim($control->getAttribute('name'));
            if ($this->isPrivateField($name)) {
                $control->parentNode?->removeChild($control);
                continue;
            }
            $tag = strtolower($control->tagName);
            $type = strtolower($control->getAttribute('type') ?: 'text');
            $isSupportedControl = in_array($tag, ['input', 'select', 'textarea'], true)
                && !$control->hasAttribute('disabled')
                && !($tag === 'input' && in_array($type, ['hidden', 'submit', 'button', 'reset', 'image', 'file'], true))
                && !($tag === 'select' && $control->hasAttribute('multiple'));
            if ($isSupportedControl && strtolower(trim($control->getAttribute('aria-required'))) === 'true') {
                $control->setAttribute('required', 'required');
            }
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

    private function hasClass(DOMElement $form, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($form->getAttribute('class'))) ?: [], true);
    }

    /** @return list<string> */
    private function hiddenFieldValues(DOMElement $form, string $name): array
    {
        $values = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            if (!$input instanceof DOMElement || $input->getAttribute('name') !== $name) {
                continue;
            }
            if (strtolower($input->getAttribute('type') ?: 'text') !== 'hidden') {
                return [];
            }
            $values[] = trim($input->getAttribute('value'));
        }
        return $values;
    }

    private function singleHiddenFieldValue(DOMElement $form, string $name): ?string
    {
        $values = $this->hiddenFieldValues($form, $name);
        return count($values) === 1 ? $values[0] : null;
    }

    private function isPrivateField(string $name): bool
    {
        return preg_match(self::PRIVATE_FIELD_PATTERN, $name) === 1;
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

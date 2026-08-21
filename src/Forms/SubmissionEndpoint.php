<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use InvalidArgumentException;

final class SubmissionEndpoint
{
    /** @var array<string, array{adapter:FormAdapter,definition:FormDefinition}> */
    private array $forms = [];

    /** @var list<string> */
    private array $allowedOrigins;

    /**
     * @param list<string> $allowedOrigins
     * @param list<array{adapter:FormAdapter,definition:FormDefinition}> $forms
     */
    public function __construct(private SubmissionStore $store, array $allowedOrigins, array $forms)
    {
        if ($allowedOrigins === []) {
            throw new InvalidArgumentException('Submission endpoint requires at least one allowed origin.');
        }
        $this->allowedOrigins = array_map([$this, 'normalizeOrigin'], $allowedOrigins);

        foreach ($forms as $route) {
            $id = $route['definition']->formId();
            if (isset($this->forms[$id])) {
                throw new InvalidArgumentException('Submission form identifiers must be unique.');
            }
            $this->forms[$id] = $route;
        }
    }

    /** @param array<string, mixed> $payload */
    public function submit(array $payload, ?string $origin): Submission
    {
        if ($origin === null || !in_array($this->normalizeOrigin($origin), $this->allowedOrigins, true)) {
            throw new SubmissionValidationException('Submission origin is not allowed.');
        }

        $formId = $payload[GenericHtmlFormAdapter::FORM_ID_FIELD] ?? null;
        if (!is_string($formId) || !isset($this->forms[$formId])) {
            throw new SubmissionValidationException('Submission form identifier is unknown.');
        }

        $route = $this->forms[$formId];
        $submission = $route['adapter']->validateSubmission($route['definition'], $payload);
        $this->store->save($submission);

        return $submission;
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new InvalidArgumentException('Allowed origins must be absolute HTTP(S) origins.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            throw new InvalidArgumentException('Allowed origins must not include a path.');
        }

        $normalized = $scheme . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }
        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use InvalidArgumentException;
use JsonException;

final class SubmissionHttpTransport
{
    public const DEFAULT_MAX_BODY_BYTES = 65536;
    private const FORM_CONTENT_TYPE = 'application/x-www-form-urlencoded';

    public function __construct(
        private SubmissionEndpoint $endpoint,
        private int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES
    ) {
        if ($this->maxBodyBytes < 1) {
            throw new InvalidArgumentException('Submission HTTP transport requires a positive request-size limit.');
        }
    }

    public function handle(string $method, ?string $contentType, string $body, ?string $origin): SubmissionHttpResponse
    {
        if (strtoupper($method) !== 'POST') {
            return $this->jsonResponse(405, [
                'ok' => false,
                'error' => 'method_not_allowed',
            ], ['Allow' => 'POST']);
        }

        if (strlen($body) > $this->maxBodyBytes) {
            return $this->jsonResponse(413, [
                'ok' => false,
                'error' => 'request_too_large',
            ]);
        }

        if ($this->mediaType($contentType) !== self::FORM_CONTENT_TYPE) {
            return $this->jsonResponse(415, [
                'ok' => false,
                'error' => 'unsupported_media_type',
            ]);
        }

        try {
            $payload = $this->decodeFormBody($body);
        } catch (SubmissionValidationException) {
            return $this->jsonResponse(400, [
                'ok' => false,
                'error' => 'malformed_request',
            ]);
        }

        try {
            $submission = $this->endpoint->submit($payload, $origin);
        } catch (SubmissionValidationException) {
            return $this->jsonResponse(422, [
                'ok' => false,
                'error' => 'invalid_submission',
            ]);
        }

        return $this->jsonResponse(201, [
            'ok' => true,
            'form_id' => $submission->formId(),
        ]);
    }

    private function mediaType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        return $mediaType === '' ? null : $mediaType;
    }

    /** @return array<string, string> */
    private function decodeFormBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $payload = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            if ($this->hasInvalidPercentEncoding($encodedName) || $this->hasInvalidPercentEncoding($encodedValue)) {
                throw new SubmissionValidationException('Submission body contains invalid percent encoding.');
            }

            $name = urldecode($encodedName);
            $value = urldecode($encodedValue);
            if ($name === '' || preg_match('//u', $name) !== 1 || preg_match('//u', $value) !== 1) {
                throw new SubmissionValidationException('Submission body must contain valid UTF-8 field names and values.');
            }
            if (array_key_exists($name, $payload)) {
                throw new SubmissionValidationException('Submission body contains duplicate field names.');
            }

            $payload[$name] = $value;
        }

        return $payload;
    }

    private function hasInvalidPercentEncoding(string $value): bool
    {
        return preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1;
    }

    /**
     * @param array<string, scalar|bool|null> $payload
     * @param array<string, string> $headers
     */
    private function jsonResponse(int $statusCode, array $payload, array $headers = []): SubmissionHttpResponse
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode submission HTTP response.', 0, $exception);
        }

        return new SubmissionHttpResponse(
            $statusCode,
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers,
            $body . "\n"
        );
    }
}

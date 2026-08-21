<?php

declare(strict_types=1);

namespace WPStaticSecure\Fetching;

final class HttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        private int $statusCode,
        private array $headers,
        private string $body
    ) {
    }

    public function statusCode(): int { return $this->statusCode; }
    /** @return array<string, list<string>> */
    public function headers(): array { return $this->headers; }
    public function body(): string { return $this->body; }
    public function firstHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        return $values[0] ?? null;
    }
}

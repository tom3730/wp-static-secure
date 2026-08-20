<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Configuration\Origin;

final class OriginTest extends TestCase
{
    /** @dataProvider validOrigins */
    public function test_normalizes_valid_origins(string $input, string $expected): void
    {
        self::assertSame($expected, (new Origin($input))->value());
    }

    public function validOrigins(): array
    {
        return [
            ['https://WP.EXAMPLE.test/', 'https://wp.example.test'],
            ['http://example.test:80', 'http://example.test'],
            ['https://example.test:443/', 'https://example.test'],
            ['https://example.test:8443', 'https://example.test:8443'],
            ['https://[2001:db8::1]:8443', 'https://[2001:db8::1]:8443'],
        ];
    }

    /** @dataProvider invalidOrigins */
    public function test_rejects_invalid_origins(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Origin($input);
    }

    public function invalidOrigins(): array
    {
        return [
            ['example.test'],
            ['ftp://example.test'],
            ['https://user:pass@example.test'],
            ['https://example.test/path'],
            ['https://example.test/?query=1'],
            ['https://example.test/#fragment'],
            ['https://-bad.example'],
        ];
    }
}

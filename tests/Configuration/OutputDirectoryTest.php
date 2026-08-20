<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Configuration\OutputDirectory;

final class OutputDirectoryTest extends TestCase
{
    /** @dataProvider validPaths */
    public function test_accepts_safe_absolute_paths(string $input, string $expected): void
    {
        self::assertSame($expected, (new OutputDirectory($input))->path());
    }

    public function validPaths(): array
    {
        return [
            ['/var/www/site/dist', '/var/www/site/dist'],
            ['/tmp/wp-static-secure/', '/tmp/wp-static-secure'],
            ['C:\\sites\\example\\dist', 'C:/sites/example/dist'],
        ];
    }

    /** @dataProvider invalidPaths */
    public function test_rejects_unsafe_paths(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OutputDirectory($input);
    }

    public function invalidPaths(): array
    {
        return [
            [''],
            ['dist'],
            ['/'],
            ['C:/'],
            ['/var/www/../secret'],
            ['/var/www/./dist'],
            ['//server/share/dist'],
            ["/tmp/dist\0escape"],
        ];
    }
}

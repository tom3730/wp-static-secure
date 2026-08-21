<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Discovery;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Discovery\UrlNormalizer;

final class UrlNormalizerTest extends TestCase
{
    /** @dataProvider normalizedUrls */
    public function test_normalizes_urls(string $input, ?string $base, string $expected): void
    {
        self::assertSame($expected, (new UrlNormalizer())->normalize($input, $base));
    }

    public function normalizedUrls(): array
    {
        return [
            ['HTTPS://Example.TEST:443/a/../b#fragment', null, 'https://example.test/b'],
            ['/blog/post/', 'https://example.test/base/page', 'https://example.test/blog/post/'],
            ['next?page=2#comments', 'https://example.test/blog/post/', 'https://example.test/blog/post/next?page=2'],
            ['//EXAMPLE.test:443/a', 'https://example.test/base', 'https://example.test/a'],
            ['https://example.test/blog/%2e%2e/admin', null, 'https://example.test/admin'],
            ['https://example.test/%7euser?q=%7evalue', null, 'https://example.test/~user?q=~value'],
            ['http://example.test:80', null, 'http://example.test/'],
        ];
    }

    /** @dataProvider unsafeUrls */
    public function test_rejects_unsafe_or_ambiguous_urls(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlNormalizer())->normalize($input, 'https://example.test/');
    }

    public function unsafeUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['https://user:pass@example.test/'],
            ['https://example.test/a%2Fb'],
            ['https://example.test/a%5Cb'],
            ['https://example.test/a\\b'],
            ['https://example.test/%zz'],
            ["https://example.test/a\nb"],
        ];
    }
}

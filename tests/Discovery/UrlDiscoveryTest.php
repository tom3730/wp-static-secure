<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Configuration\Origin;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Discovery\UrlDiscovery;

final class UrlDiscoveryTest extends TestCase
{
    public function test_returns_deterministic_deduplicated_classified_sets(): void
    {
        $discovery = new UrlDiscovery(new CrawlScope(new Origin('https://wp.example.test'), '/blog'));

        $result = $discovery->discover([
            '/blog/b#one',
            'https://WP.EXAMPLE.test:443/blog/a',
            '/blog/b#two',
            '/blog/../admin',
            'https://outside.example/post',
            '/blog/a%2Fb',
            '/blog/a?b=2',
            '/blog/a?b=1',
        ]);

        self::assertSame([
            'https://wp.example.test/blog/a',
            'https://wp.example.test/blog/a?b=1',
            'https://wp.example.test/blog/a?b=2',
            'https://wp.example.test/blog/b',
        ], $result->crawlUrls());
        self::assertSame(['https://outside.example/post'], $result->externalUrls());
        self::assertSame(['https://wp.example.test/admin'], $result->outOfScopeUrls());
        self::assertSame(['/blog/a%2Fb'], $result->invalidUrls());
    }
}

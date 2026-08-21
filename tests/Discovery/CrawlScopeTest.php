<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Configuration\Origin;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Discovery\UrlNormalizer;

final class CrawlScopeTest extends TestCase
{
    public function test_classifies_same_origin_and_path_scope(): void
    {
        $normalizer = new UrlNormalizer();
        $scope = new CrawlScope(new Origin('https://wp.example.test'), '/blog', $normalizer);

        self::assertSame(CrawlScope::INTERNAL, $scope->classify($normalizer->normalize('https://wp.example.test/blog')));
        self::assertSame(CrawlScope::INTERNAL, $scope->classify($normalizer->normalize('https://wp.example.test/blog/post')));
        self::assertSame(CrawlScope::OUT_OF_SCOPE, $scope->classify($normalizer->normalize('https://wp.example.test/blogger')));
        self::assertSame(CrawlScope::OUT_OF_SCOPE, $scope->classify($normalizer->normalize('https://wp.example.test/blog/%2e%2e/admin')));
        self::assertSame(CrawlScope::EXTERNAL, $scope->classify($normalizer->normalize('https://example.test/blog/post')));
        self::assertSame(CrawlScope::EXTERNAL, $scope->classify($normalizer->normalize('http://wp.example.test/blog/post')));
    }
}

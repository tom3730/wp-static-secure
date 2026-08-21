<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Assets;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Assets\HtmlAssetProcessor;

final class HtmlAssetProcessorTest extends TestCase
{
    public function testDiscoversAndRewritesInternalAssetsWhilePreservingExternalResources(): void
    {
        $html = <<<'HTML'
<!doctype html><html><head>
<link rel="stylesheet" href="https://wp.example/wp-content/theme.css?ver=1">
<link rel="icon" href="/favicon.ico">
<link rel="canonical" href="https://wp.example/posts/one/">
<meta property="og:url" content="https://wp.example/posts/one/">
<meta property="og:image" content="https://wp.example/uploads/hero.jpg">
<script src="https://cdn.example/library.js"></script>
</head><body>
<img src="/uploads/photo.jpg" srcset="/uploads/photo-320.jpg 320w, https://wp.example/uploads/photo-640.jpg 640w">
<a href="/files/guide.pdf">Guide</a>
</body></html>
HTML;

        $result = (new HtmlAssetProcessor())->process($html, 'https://wp.example/posts/one/', 'https://wp.example', 'https://www.example.com');

        self::assertSame([
            'https://wp.example/favicon.ico',
            'https://wp.example/files/guide.pdf',
            'https://wp.example/uploads/hero.jpg',
            'https://wp.example/uploads/photo-320.jpg',
            'https://wp.example/uploads/photo-640.jpg',
            'https://wp.example/uploads/photo.jpg',
            'https://wp.example/wp-content/theme.css?ver=1',
        ], $result->assetUrls());
        self::assertStringContainsString('https://www.example.com/wp-content/theme.css?ver=1', $result->body());
        self::assertStringContainsString('https://www.example.com/posts/one/', $result->body());
        self::assertStringContainsString('https://www.example.com/uploads/hero.jpg', $result->body());
        self::assertStringContainsString('https://www.example.com/uploads/photo-640.jpg 640w', $result->body());
        self::assertStringContainsString('https://cdn.example/library.js', $result->body());
        self::assertStringNotContainsString('https://wp.example/', $result->body());
    }

    public function testRelativeAndExternalReferencesAreNotNeedlesslyRewritten(): void
    {
        $result = (new HtmlAssetProcessor())->process(
            '<img src="../images/a.jpg"><script src="https://cdn.example/a.js"></script>',
            'https://wp.example/blog/post/',
            'https://wp.example',
            'https://www.example.com'
        );

        self::assertSame(['https://wp.example/blog/images/a.jpg'], $result->assetUrls());
        self::assertStringContainsString('../images/a.jpg', $result->body());
        self::assertStringContainsString('https://cdn.example/a.js', $result->body());
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Assets;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Assets\CssAssetProcessor;

final class CssAssetProcessorTest extends TestCase
{
    public function testDiscoversRelativeFontsAndImagesAndRewritesAuthoringOrigin(): void
    {
        $css = '@font-face{src:url("../fonts/site.woff2")} .hero{background:url(https://wp.example/uploads/bg.jpg)} .external{background:url(https://cdn.example/bg.jpg)} .inline{background:url(data:image/png;base64,AAAA)}';
        $result = (new CssAssetProcessor())->process($css, 'https://wp.example/wp-content/css/site.css?ver=2', 'https://wp.example', 'https://www.example.com');

        self::assertSame([
            'https://wp.example/uploads/bg.jpg',
            'https://wp.example/wp-content/fonts/site.woff2',
        ], $result->assetUrls());
        self::assertStringContainsString('url("../fonts/site.woff2")', $result->body());
        self::assertStringContainsString('url(https://www.example.com/uploads/bg.jpg)', $result->body());
        self::assertStringContainsString('url(https://cdn.example/bg.jpg)', $result->body());
        self::assertStringContainsString('url(data:image/png;base64,AAAA)', $result->body());
    }
}

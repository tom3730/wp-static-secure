<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Discovery\SitemapUrlExtractor;

final class SitemapUrlExtractorTest extends TestCase
{
    public function test_extracts_urlset_and_sitemap_index_locations_without_entity_resolution(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.test/a?x=1&amp;y=2</loc></url>
  <url><loc><![CDATA[https://example.test/b]]></loc></url>
</urlset>
XML;

        self::assertSame([
            'https://example.test/a?x=1&y=2',
            'https://example.test/b',
        ], (new SitemapUrlExtractor())->extract($xml));
    }

    public function test_does_not_expand_declared_external_entities(): void
    {
        $xml = <<<'XML'
<!DOCTYPE urlset [<!ENTITY secret SYSTEM "file:///etc/passwd">]>
<urlset><url><loc>&secret;</loc></url></urlset>
XML;

        self::assertSame(['&secret;'], (new SitemapUrlExtractor())->extract($xml));
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Assets;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Assets\AssetExporter;
use WPStaticSecure\Assets\HtmlAssetProcessor;
use WPStaticSecure\Configuration\Origin;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Fetching\FetchException;
use WPStaticSecure\Fetching\FilesystemPageStore;
use WPStaticSecure\Fetching\HttpFetcher;
use WPStaticSecure\Fetching\HttpResponse;
use WPStaticSecure\Fetching\PageExporter;

final class PageExporterAssetIntegrationTest extends TestCase
{
    private string $dist;

    protected function setUp(): void
    {
        $this->dist = sys_get_temp_dir() . '/wp-static-secure-site-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dist);
    }

    public function testPageExportRewritesPrivateOriginAndDownloadsDiscoveredAssets(): void
    {
        $fetcher = new class implements HttpFetcher {
            public function fetch(string $url): HttpResponse
            {
                return match ($url) {
                    'https://wp.example/' => new HttpResponse(200, ['content-type' => ['text/html; charset=UTF-8']], '<!doctype html><html><head><link rel="stylesheet" href="https://wp.example/theme.css"><link rel="canonical" href="https://wp.example/"><meta property="og:image" content="https://wp.example/hero.jpg"></head><body><img src="/hero.jpg"><script src="https://cdn.example/app.js"></script></body></html>'),
                    'https://wp.example/theme.css' => new HttpResponse(200, ['content-type' => ['text/css']], 'body{background:url(/bg.png)}'),
                    'https://wp.example/hero.jpg' => new HttpResponse(200, ['content-type' => ['image/jpeg']], 'hero'),
                    'https://wp.example/bg.png' => new HttpResponse(200, ['content-type' => ['image/png']], 'bg'),
                    default => throw new FetchException('unexpected ' . $url),
                };
            }
        };

        $scope = new CrawlScope(new Origin('https://wp.example'));
        $store = new FilesystemPageStore($this->dist);
        $assetExporter = new AssetExporter($fetcher, $scope, $store);
        $exporter = new PageExporter($fetcher, $scope, $store, null, null, new HtmlAssetProcessor(), $assetExporter, 'https://www.example.com');

        $results = $exporter->export(['https://wp.example/']);
        $html = (string) file_get_contents($this->dist . '/index.html');

        self::assertStringNotContainsString('https://wp.example', $html);
        self::assertStringContainsString('https://www.example.com/theme.css', $html);
        self::assertStringContainsString('https://www.example.com/hero.jpg', $html);
        self::assertStringContainsString('https://cdn.example/app.js', $html);
        self::assertFileExists($this->dist . '/theme.css');
        self::assertFileExists($this->dist . '/hero.jpg');
        self::assertFileExists($this->dist . '/bg.png');
        self::assertSame(4, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'written')));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

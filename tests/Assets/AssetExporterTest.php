<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Assets;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Assets\AssetExporter;
use WPStaticSecure\Configuration\Origin;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Fetching\FetchException;
use WPStaticSecure\Fetching\FilesystemPageStore;
use WPStaticSecure\Fetching\HttpFetcher;
use WPStaticSecure\Fetching\HttpResponse;

final class AssetExporterTest extends TestCase
{
    private string $dist;

    protected function setUp(): void
    {
        $this->dist = sys_get_temp_dir() . '/wp-static-secure-assets-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dist);
    }

    public function testDownloadsInScopeAssetsAndRecursivelyProcessesCssResources(): void
    {
        $fetcher = new class implements HttpFetcher {
            public function fetch(string $url): HttpResponse
            {
                return match ($url) {
                    'https://wp.example/wp-content/site.css?ver=1' => new HttpResponse(200, ['content-type' => ['text/css']], '.hero{background:url(../images/bg.jpg)} @font-face{src:url(https://wp.example/fonts/site.woff2)}'),
                    'https://wp.example/images/bg.jpg' => new HttpResponse(200, ['content-type' => ['image/jpeg']], 'image'),
                    'https://wp.example/fonts/site.woff2' => new HttpResponse(200, ['content-type' => ['font/woff2']], 'font'),
                    default => throw new FetchException('unexpected ' . $url),
                };
            }
        };

        $scope = new CrawlScope(new Origin('https://wp.example'));
        $exporter = new AssetExporter($fetcher, $scope, new FilesystemPageStore($this->dist));
        $results = $exporter->export(['https://wp.example/wp-content/site.css?ver=1'], 'https://www.example.com');

        self::assertFileExists($this->dist . '/wp-content/site.css');
        self::assertFileExists($this->dist . '/images/bg.jpg');
        self::assertFileExists($this->dist . '/fonts/site.woff2');
        self::assertStringContainsString('url(../images/bg.jpg)', (string) file_get_contents($this->dist . '/wp-content/site.css'));
        self::assertStringContainsString('url(https://www.example.com/fonts/site.woff2)', (string) file_get_contents($this->dist . '/wp-content/site.css'));
        self::assertCount(3, array_filter($results, static fn (array $result): bool => $result['status'] === 'written'));
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

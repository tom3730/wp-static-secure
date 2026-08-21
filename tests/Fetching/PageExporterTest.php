<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Fetching;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Configuration\Origin;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Fetching\FetchException;
use WPStaticSecure\Fetching\FilesystemPageStore;
use WPStaticSecure\Fetching\HttpFetcher;
use WPStaticSecure\Fetching\HttpResponse;
use WPStaticSecure\Fetching\OutputPathMapper;
use WPStaticSecure\Fetching\PageExporter;

final class PageExporterTest extends TestCase
{
    private string $dist;

    protected function setUp(): void
    {
        $this->dist = sys_get_temp_dir() . '/wp-static-secure-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dist);
    }

    public function testOutputPathsAreDeterministic(): void
    {
        $mapper = new OutputPathMapper();
        self::assertSame('index.html', $mapper->map('https://wp.example/'));
        self::assertSame('about/index.html', $mapper->map('https://wp.example/about/'));
        self::assertSame('about/index.html', $mapper->map('https://wp.example/about'));
        self::assertSame('robots.txt', $mapper->map('https://wp.example/robots.txt'));
    }

    public function testQueryStringIsRejectedInsteadOfCollidingWithPathOutput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OutputPathMapper())->map('https://wp.example/about/?preview=1');
    }

    public function testWritesOnlySuccessfulInScopePagesAndPersistsManifest(): void
    {
        $fetcher = new class implements HttpFetcher {
            public function fetch(string $url): HttpResponse
            {
                return match ($url) {
                    'https://wp.example/' => new HttpResponse(200, ['content-type' => ['text/html; charset=UTF-8']], '<a href="/about/">About</a>'),
                    'https://wp.example/about/' => new HttpResponse(200, ['content-type' => ['text/html']], '<h1>About</h1>'),
                    'https://wp.example/old/' => new HttpResponse(301, ['location' => ['https://outside.example/']], ''),
                    'https://wp.example/missing/' => new HttpResponse(404, ['content-type' => ['text/html']], 'missing'),
                    default => throw new FetchException('network down'),
                };
            }
        };

        $scope = new CrawlScope(new Origin('https://wp.example'));
        $exporter = new PageExporter($fetcher, $scope, new FilesystemPageStore($this->dist));
        $results = $exporter->export([
            'https://wp.example/',
            'https://wp.example/about/',
            'https://wp.example/old/',
            'https://wp.example/missing/',
            'https://wp.example/network/',
            'https://other.example/',
        ]);

        self::assertSame('<a href="/about/">About</a>', file_get_contents($this->dist . '/index.html'));
        self::assertSame('<h1>About</h1>', file_get_contents($this->dist . '/about/index.html'));
        self::assertFileDoesNotExist($this->dist . '/old/index.html');
        self::assertFileDoesNotExist($this->dist . '/missing/index.html');

        self::assertSame(['written', 'written', 'redirect', 'http_error', 'network_error', 'out_of_scope'], array_column($results, 'status'));
        self::assertSame('https://outside.example/', $results[2]['location']);
        self::assertSame(404, $results[3]['status_code']);

        $manifest = json_decode((string) file_get_contents($this->dist . '/.wp-static-secure/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['https://wp.example/', 'https://wp.example/about/'], array_column($manifest['pages'], 'url'));
        self::assertSame(['index.html', 'about/index.html'], array_column($manifest['pages'], 'output_path'));
    }

    public function testUnsafeNormalizedPathCannotEscapeOutputDirectory(): void
    {
        $fetcher = new class implements HttpFetcher {
            public function fetch(string $url): HttpResponse
            {
                return new HttpResponse(200, ['content-type' => ['text/html']], 'safe');
            }
        };
        $scope = new CrawlScope(new Origin('https://wp.example'), '/site/');
        $exporter = new PageExporter($fetcher, $scope, new FilesystemPageStore($this->dist));

        $results = $exporter->export(['https://wp.example/site/%2e%2e/secret/']);
        self::assertSame('out_of_scope', $results[0]['status']);
        self::assertFileDoesNotExist(dirname($this->dist) . '/secret/index.html');
    }

    public function testStoreRejectsTraversalPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FilesystemPageStore($this->dist))->write('../escape.html', 'nope');
    }

    public function testStoreRefusesExistingSymlinkEscape(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is unavailable.');
        }
        mkdir($this->dist, 0775, true);
        $outside = sys_get_temp_dir() . '/wp-static-secure-outside-' . bin2hex(random_bytes(4));
        mkdir($outside, 0775, true);
        if (!@symlink($outside, $this->dist . '/linked')) {
            $this->removeTree($outside);
            self::markTestSkipped('Unable to create a symlink in this environment.');
        }

        try {
            $this->expectException(\RuntimeException::class);
            (new FilesystemPageStore($this->dist))->write('linked/index.html', 'nope');
        } finally {
            @unlink($this->dist . '/linked');
            $this->removeTree($outside);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_link($child) || is_file($child)) {
                @unlink($child);
            } else {
                $this->removeTree($child);
            }
        }
        @rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Acceptance;

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
use WPStaticSecure\Validation\BuildValidator;

final class StaticOnlyAcceptanceTest extends TestCase
{
    private string $dist;

    protected function setUp(): void
    {
        $this->dist = sys_get_temp_dir() . '/wp-static-secure-acceptance-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dist);
    }

    public function testRepresentativeSiteWorksFromStaticOutputWithoutWordPressFallback(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is required for static-server acceptance testing.');
        }

        /** @var array<string, array{0:string,1:string}> $fixture */
        $fixture = require dirname(__DIR__) . '/Fixtures/representative-site.php';
        $fetcher = new class($fixture) implements HttpFetcher {
            /** @param array<string, array{0:string,1:string}> $fixture */
            public function __construct(private array $fixture) {}

            public function fetch(string $url): HttpResponse
            {
                if (!isset($this->fixture[$url])) {
                    throw new FetchException('Unexpected authoring request: ' . $url);
                }
                [$contentType, $body] = $this->fixture[$url];
                return new HttpResponse(200, ['content-type' => [$contentType]], $body);
            }
        };

        $scope = new CrawlScope(new Origin('https://wp.internal.example'));
        $store = new FilesystemPageStore($this->dist);
        $assets = new AssetExporter($fetcher, $scope, $store);
        $exporter = new PageExporter($fetcher, $scope, $store, null, null, new HtmlAssetProcessor(), $assets, 'https://www.example.com');
        $results = $exporter->export(['https://wp.internal.example/', 'https://wp.internal.example/about/']);

        self::assertSame(4, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'written')));
        $report = (new BuildValidator($this->dist, 'https://wp.internal.example', 'https://www.example.com'))->validate();
        self::assertTrue($report->isSuccessful(), json_encode($report->issues(), JSON_PRETTY_PRINT));

        [$process, $pipes, $port] = $this->startStaticServer();
        try {
            [$status, $body] = $this->request($port, '/');
            self::assertSame(200, $status);
            self::assertStringContainsString('https://www.example.com/about/', $body);

            [$status, $body] = $this->request($port, '/about/');
            self::assertSame(200, $status);
            self::assertStringContainsString('<h1>About</h1>', $body);

            self::assertSame(200, $this->request($port, '/assets/site.css')[0]);
            self::assertSame(200, $this->request($port, '/assets/logo.svg')[0]);
            self::assertSame(404, $this->request($port, '/wp-login.php')[0]);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }

    /** @return array{resource,array<int,resource>,int} */
    private function startStaticServer(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail('Unable to reserve acceptance-test port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->dist],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            self::fail('Unable to start PHP static server.');
        }

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return [$process, $pipes, $port];
            }
            usleep(50000);
        }

        proc_terminate($process);
        self::fail('PHP static server did not become ready.');
    }

    /** @return array{int,string} */
    private function request(int $port, string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
        $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
        $headers = $http_response_header ?? [];
        $status = isset($headers[0]) && preg_match('~\s(\d{3})\s~', $headers[0], $match) === 1 ? (int) $match[1] : 0;
        return [$status, is_string($body) ? $body : ''];
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

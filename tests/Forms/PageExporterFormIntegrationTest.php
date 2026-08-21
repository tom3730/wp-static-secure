<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Forms;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Configuration\Origin;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Fetching\FilesystemPageStore;
use WPStaticSecure\Fetching\HttpFetcher;
use WPStaticSecure\Fetching\HttpResponse;
use WPStaticSecure\Fetching\PageExporter;
use WPStaticSecure\Forms\FormProcessor;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;

final class PageExporterFormIntegrationTest extends TestCase
{
    private string $dist;

    protected function setUp(): void
    {
        $this->dist = sys_get_temp_dir() . '/wp-static-secure-form-site-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dist)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dist, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dist);
    }

    public function testSupportedFormIsRewrittenDuringPageExport(): void
    {
        $fetcher = new class implements HttpFetcher {
            public function fetch(string $url): HttpResponse
            {
                return new HttpResponse(200, ['content-type' => ['text/html; charset=UTF-8']], '<form data-wpss-form="contact" action="https://wp.example/wp-admin/admin-post.php"><input name="email"><button>Send</button></form>');
            }
        };
        $processor = new FormProcessor([new GenericHtmlFormAdapter()], 'https://forms.example.test/submit');
        $exporter = new PageExporter(
            $fetcher,
            new CrawlScope(new Origin('https://wp.example')),
            new FilesystemPageStore($this->dist),
            null,
            null,
            null,
            null,
            null,
            $processor
        );

        $exporter->export(['https://wp.example/']);
        $html = (string) file_get_contents($this->dist . '/index.html');

        self::assertStringContainsString('https://forms.example.test/submit', $html);
        self::assertStringContainsString('_wpss_form_id', $html);
        self::assertStringNotContainsString('wp-admin/admin-post.php', $html);
    }
}

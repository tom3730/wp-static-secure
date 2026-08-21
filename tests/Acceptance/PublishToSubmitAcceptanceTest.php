<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Acceptance;

use DOMDocument;
use DOMElement;
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
use WPStaticSecure\Forms\FormProcessor;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;
use WPStaticSecure\Forms\SubmissionEndpoint;
use WPStaticSecure\Forms\SubmissionHttpTransport;
use WPStaticSecure\Forms\SubmissionStatus;
use WPStaticSecure\Forms\WordPressSubmissionStore;
use WPStaticSecure\Validation\BuildValidator;

final class PublishToSubmitAcceptanceTest extends TestCase
{
    private const AUTHORING_ORIGIN = 'https://wp.internal.example';
    private const PUBLIC_ORIGIN = 'https://www.example.com';
    private const SUBMISSION_ENDPOINT = 'https://forms.example.test/submit';

    private string $dist;

    protected function setUp(): void
    {
        $this->dist = sys_get_temp_dir() . '/wp-static-secure-publish-submit-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dist);
    }

    public function testPublishValidationAndPublicSubmissionReachInboxWithoutWordPressHttpAccess(): void
    {
        /** @var array<string, array{0:string,1:string}> $fixture */
        $fixture = require dirname(__DIR__) . '/Fixtures/publish-to-submit-site.php';
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

        $scope = new CrawlScope(new Origin(self::AUTHORING_ORIGIN));
        $store = new FilesystemPageStore($this->dist);
        $assets = new AssetExporter($fetcher, $scope, $store);
        $adapter = new GenericHtmlFormAdapter();
        $forms = new FormProcessor([$adapter], self::SUBMISSION_ENDPOINT);
        $exporter = new PageExporter(
            $fetcher,
            $scope,
            $store,
            null,
            null,
            new HtmlAssetProcessor(),
            $assets,
            self::PUBLIC_ORIGIN,
            $forms
        );

        $results = $exporter->export([
            self::AUTHORING_ORIGIN . '/',
            self::AUTHORING_ORIGIN . '/contact/',
        ]);

        self::assertSame(4, count(array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'written'
        )));

        $report = (new BuildValidator(
            $this->dist,
            self::AUTHORING_ORIGIN,
            self::PUBLIC_ORIGIN,
            self::SUBMISSION_ENDPOINT
        ))->validate();
        self::assertTrue($report->isSuccessful(), json_encode($report->issues(), JSON_PRETTY_PRINT));
        self::assertSame([], $report->issues());

        $html = (string) file_get_contents($this->dist . '/contact/index.html');
        self::assertStringContainsString(self::SUBMISSION_ENDPOINT, $html);
        self::assertStringContainsString(GenericHtmlFormAdapter::FORM_ID_FIELD, $html);
        self::assertStringNotContainsString(self::AUTHORING_ORIGIN, $html);
        self::assertStringNotContainsString('wp-admin/', $html);
        self::assertStringNotContainsString('wp-json/', $html);
        self::assertStringNotContainsString('wp-login.php', $html);
        self::assertStringNotContainsString('xmlrpc.php', $html);
        self::assertStringNotContainsString('_wpnonce', $html);

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        self::assertSame('post', strtolower($form->getAttribute('method')));
        self::assertSame(self::SUBMISSION_ENDPOINT, $form->getAttribute('action'));
        $definition = $adapter->extractSchema($form);

        $wpdb = new class {
            public string $prefix = 'wp_';
            /** @var list<array<string, mixed>> */
            public array $rows = [];

            public function insert(string $table, array $data, array $formats): int|false
            {
                $data['id'] = count($this->rows) + 1;
                $this->rows[] = $data;
                return 1;
            }
        };
        $transport = new SubmissionHttpTransport(new SubmissionEndpoint(
            new WordPressSubmissionStore($wpdb),
            [self::PUBLIC_ORIGIN],
            [['adapter' => $adapter, 'definition' => $definition]]
        ));

        $accepted = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded; charset=UTF-8',
            '_wpss_form_id=contact&message=Hello+from+static&email=user%40example.com',
            self::PUBLIC_ORIGIN
        );

        self::assertSame(201, $accepted->statusCode());
        self::assertCount(1, $wpdb->rows);
        self::assertSame('contact', $wpdb->rows[0]['form_id']);
        self::assertSame(SubmissionStatus::NEW, $wpdb->rows[0]['status']);
        self::assertSame(
            ['email' => 'user@example.com', 'message' => 'Hello from static'],
            json_decode((string) $wpdb->rows[0]['fields_json'], true, 512, JSON_THROW_ON_ERROR)
        );

        $wrongOrigin = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=attacker%40example.com&message=blocked',
            'https://evil.example'
        );
        self::assertSame(422, $wrongOrigin->statusCode());

        $unknownField = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=user%40example.com&message=Hello&admin=true',
            self::PUBLIC_ORIGIN
        );
        self::assertSame(422, $unknownField->statusCode());
        self::assertCount(1, $wpdb->rows);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

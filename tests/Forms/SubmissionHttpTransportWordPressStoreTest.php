<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Forms;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;
use WPStaticSecure\Forms\SubmissionEndpoint;
use WPStaticSecure\Forms\SubmissionHttpTransport;
use WPStaticSecure\Forms\SubmissionStatus;
use WPStaticSecure\Forms\WordPressSubmissionStore;

final class SubmissionHttpTransportWordPressStoreTest extends TestCase
{
    public function testAcceptedHttpSubmissionIsPersistedWithNewStatus(): void
    {
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

        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="contact"><input name="email"></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        $definition = $adapter->extractSchema($form);

        $transport = new SubmissionHttpTransport(new SubmissionEndpoint(
            new WordPressSubmissionStore($wpdb),
            ['https://www.example.com'],
            [['adapter' => $adapter, 'definition' => $definition]]
        ));

        $response = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=user%40example.com',
            'https://www.example.com'
        );

        self::assertSame(201, $response->statusCode());
        self::assertCount(1, $wpdb->rows);
        self::assertSame('contact', $wpdb->rows[0]['form_id']);
        self::assertSame(SubmissionStatus::NEW, $wpdb->rows[0]['status']);
        self::assertSame('{"email":"user@example.com"}', $wpdb->rows[0]['fields_json']);
    }
}

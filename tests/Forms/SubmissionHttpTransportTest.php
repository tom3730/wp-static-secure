<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Forms;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Forms\FormProcessor;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;
use WPStaticSecure\Forms\JsonlSubmissionStore;
use WPStaticSecure\Forms\SubmissionEndpoint;
use WPStaticSecure\Forms\SubmissionHttpTransport;

final class SubmissionHttpTransportTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/wp-static-secure-http-submissions-' . bin2hex(random_bytes(6)) . '/submissions.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir(dirname($this->path))) {
            rmdir(dirname($this->path));
        }
    }

    public function testGeneratedFormSubmitsThroughTransportToDurableStorage(): void
    {
        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="contact"><input name="email"><textarea name="message"></textarea></form>');
        $processor = new FormProcessor([$adapter], 'https://forms.example.test/submit');
        $definitions = $processor->rewrite($document);

        self::assertCount(1, $definitions);
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        self::assertSame('post', $form->getAttribute('method'));
        self::assertSame('https://forms.example.test/submit', $form->getAttribute('action'));

        $transport = new SubmissionHttpTransport(new SubmissionEndpoint(
            new JsonlSubmissionStore($this->path),
            ['https://www.example.com'],
            [['adapter' => $adapter, 'definition' => $definitions[0]]]
        ));
        $response = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded; charset=UTF-8',
            '_wpss_form_id=contact&email=user%40example.com&message=Hello+world',
            'https://www.example.com'
        );

        self::assertSame(201, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame(['ok' => true, 'form_id' => 'contact'], json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR));
        self::assertFileExists($this->path);
        $stored = json_decode(trim((string) file_get_contents($this->path)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'form_id' => 'contact',
            'fields' => ['email' => 'user@example.com', 'message' => 'Hello world'],
        ], $stored);
    }

    public function testInvalidOriginIsRejectedWithoutStorage(): void
    {
        $response = $this->transport()->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=user%40example.com',
            'https://evil.example'
        );

        self::assertSame(422, $response->statusCode());
        self::assertFileDoesNotExist($this->path);
    }

    public function testUnknownFormIsRejectedWithoutStorage(): void
    {
        $response = $this->transport()->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=unknown&email=user%40example.com',
            'https://www.example.com'
        );

        self::assertSame(422, $response->statusCode());
        self::assertFileDoesNotExist($this->path);
    }

    public function testUnknownFieldIsRejectedWithoutStorage(): void
    {
        $response = $this->transport()->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=user%40example.com&admin=true',
            'https://www.example.com'
        );

        self::assertSame(422, $response->statusCode());
        self::assertFileDoesNotExist($this->path);
    }

    public function testOversizedRequestIsRejectedBeforeSubmissionValidation(): void
    {
        $response = $this->transport(32)->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=' . str_repeat('a', 64),
            'https://www.example.com'
        );

        self::assertSame(413, $response->statusCode());
        self::assertSame('request_too_large', $this->errorCode($response->body()));
        self::assertFileDoesNotExist($this->path);
    }

    public function testUnsupportedContentTypeIsRejected(): void
    {
        $response = $this->transport()->handle(
            'POST',
            'application/json',
            '{"_wpss_form_id":"contact"}',
            'https://www.example.com'
        );

        self::assertSame(415, $response->statusCode());
        self::assertSame('unsupported_media_type', $this->errorCode($response->body()));
    }

    public function testNonPostMethodIsRejectedWithAllowHeader(): void
    {
        $response = $this->transport()->handle('GET', null, '', 'https://www.example.com');

        self::assertSame(405, $response->statusCode());
        self::assertSame('POST', $response->headers()['Allow']);
    }

    public function testMalformedPercentEncodingAndDuplicateFieldsAreRejected(): void
    {
        $transport = $this->transport();

        $malformed = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=%ZZ',
            'https://www.example.com'
        );
        self::assertSame(400, $malformed->statusCode());

        $duplicate = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=contact&email=a%40example.com&email=b%40example.com',
            'https://www.example.com'
        );
        self::assertSame(400, $duplicate->statusCode());
        self::assertFileDoesNotExist($this->path);
    }

    public function testUrlEncodedFieldNamesAreNotNormalizedByPhpParsingRules(): void
    {
        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="profile"><input name="user.email"></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        $definition = $adapter->extractSchema($form);
        $transport = new SubmissionHttpTransport(new SubmissionEndpoint(
            new JsonlSubmissionStore($this->path),
            ['https://www.example.com'],
            [['adapter' => $adapter, 'definition' => $definition]]
        ));

        $response = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded',
            '_wpss_form_id=profile&user.email=user%40example.com',
            'https://www.example.com'
        );

        self::assertSame(201, $response->statusCode());
        $stored = json_decode(trim((string) file_get_contents($this->path)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['user.email' => 'user@example.com'], $stored['fields']);
    }

    private function transport(int $maxBodyBytes = SubmissionHttpTransport::DEFAULT_MAX_BODY_BYTES): SubmissionHttpTransport
    {
        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="contact"><input name="email"><textarea name="message"></textarea></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        $definition = $adapter->extractSchema($form);

        return new SubmissionHttpTransport(new SubmissionEndpoint(
            new JsonlSubmissionStore($this->path),
            ['https://www.example.com'],
            [['adapter' => $adapter, 'definition' => $definition]]
        ), $maxBodyBytes);
    }

    private function errorCode(string $body): string
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return (string) $decoded['error'];
    }
}

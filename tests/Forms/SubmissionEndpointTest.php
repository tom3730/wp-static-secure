<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Forms;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;
use WPStaticSecure\Forms\JsonlSubmissionStore;
use WPStaticSecure\Forms\SubmissionEndpoint;
use WPStaticSecure\Forms\SubmissionValidationException;

final class SubmissionEndpointTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/wp-static-secure-submissions-' . bin2hex(random_bytes(6)) . '/submissions.jsonl';
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

    public function testValidSubmissionIsPersistedBeforeAnyNotificationConcern(): void
    {
        [$adapter, $definition] = $this->route();
        $endpoint = new SubmissionEndpoint(
            new JsonlSubmissionStore($this->path),
            ['https://www.example.com'],
            [['adapter' => $adapter, 'definition' => $definition]]
        );

        $submission = $endpoint->submit([
            '_wpss_form_id' => 'contact',
            'email' => 'a@example.test',
            'message' => 'Hello',
        ], 'https://www.example.com');

        self::assertSame('contact', $submission->formId());
        self::assertFileExists($this->path);
        $record = json_decode(trim((string) file_get_contents($this->path)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($submission->toArray(), $record);
    }

    public function testOriginMustBeExplicitlyAllowed(): void
    {
        [$adapter, $definition] = $this->route();
        $endpoint = new SubmissionEndpoint(
            new JsonlSubmissionStore($this->path),
            ['https://www.example.com'],
            [['adapter' => $adapter, 'definition' => $definition]]
        );

        $this->expectException(SubmissionValidationException::class);
        $endpoint->submit(['_wpss_form_id' => 'contact', 'email' => 'a@example.test'], 'https://evil.example');
    }

    public function testUnknownFormIdentifierIsRejectedWithoutStorage(): void
    {
        [$adapter, $definition] = $this->route();
        $endpoint = new SubmissionEndpoint(
            new JsonlSubmissionStore($this->path),
            ['https://www.example.com'],
            [['adapter' => $adapter, 'definition' => $definition]]
        );

        try {
            $endpoint->submit(['_wpss_form_id' => 'unknown', 'email' => 'a@example.test'], 'https://www.example.com');
            self::fail('Expected submission validation to fail.');
        } catch (SubmissionValidationException) {
            self::assertFileDoesNotExist($this->path);
        }
    }

    /** @return array{GenericHtmlFormAdapter, \WPStaticSecure\Forms\FormDefinition} */
    private function route(): array
    {
        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="contact"><input name="email"><textarea name="message"></textarea></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        return [$adapter, $adapter->extractSchema($form)];
    }
}

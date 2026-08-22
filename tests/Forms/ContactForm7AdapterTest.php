<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Forms;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Forms\ContactForm7Adapter;
use WPStaticSecure\Forms\FormProcessor;
use WPStaticSecure\Forms\JsonlSubmissionStore;
use WPStaticSecure\Forms\SubmissionEndpoint;
use WPStaticSecure\Forms\SubmissionHttpTransport;

final class ContactForm7AdapterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/wp-static-secure-cf7-' . bin2hex(random_bytes(6)) . '/submissions.jsonl';
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

    public function testRepresentativeRenderedContactForm7FixtureIsDetectedAndRewritten(): void
    {
        $adapter = new ContactForm7Adapter();
        $document = $this->fixtureDocument();
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);

        self::assertTrue($adapter->supports($form));
        $definition = $adapter->extractSchema($form);
        self::assertSame('cf7-123', $definition->formId());
        self::assertSame(['your-email', 'your-message', 'your-name', 'your-subject'], $definition->allowedFields());

        $adapter->rewrite($form, $definition, 'https://forms.example.test/submit');
        $html = $document->saveHTML($form);
        self::assertIsString($html);
        self::assertSame('https://forms.example.test/submit', $form->getAttribute('action'));
        self::assertSame('post', $form->getAttribute('method'));
        self::assertSame('cf7-123', $form->getAttribute('data-wpss-form'));
        self::assertFalse($form->hasAttribute('novalidate'));
        self::assertSame('required', $this->controlByName($form, 'your-name')->getAttribute('required'));
        self::assertSame('required', $this->controlByName($form, 'your-email')->getAttribute('required'));
        self::assertStringNotContainsString('wpcf7-form', $form->getAttribute('class'));
        $container = $form->parentNode;
        self::assertInstanceOf(DOMElement::class, $container);
        self::assertStringNotContainsString('wpcf7', $container->getAttribute('class'));
        self::assertFalse($container->hasAttribute('data-wpcf7-id'));
        self::assertStringNotContainsString('_wpcf7', $html);
        self::assertStringNotContainsString('_wpnonce', $html);
        self::assertStringNotContainsString('wp.example.test', $html);
        self::assertStringNotContainsString('wp-json', $html);
        self::assertStringNotContainsString('admin-ajax', $html);
        self::assertStringContainsString('name="_wpss_form_id"', $html);
        self::assertStringContainsString('value="cf7-123"', $html);
    }

    public function testRecognizedContactForm7SubmissionUsesExistingHttpTransport(): void
    {
        $adapter = new ContactForm7Adapter();
        $document = $this->fixtureDocument();
        $processor = new FormProcessor([$adapter], 'https://forms.example.test/submit');
        $definitions = $processor->rewrite($document);
        self::assertCount(1, $definitions);

        $transport = new SubmissionHttpTransport(new SubmissionEndpoint(
            new JsonlSubmissionStore($this->path),
            ['https://www.example.test'],
            [['adapter' => $adapter, 'definition' => $definitions[0]]]
        ));
        $response = $transport->handle(
            'POST',
            'application/x-www-form-urlencoded; charset=UTF-8',
            '_wpss_form_id=cf7-123&your-name=Ryo&your-email=ryo%40example.test&your-subject=Hello&your-message=Static+works',
            'https://www.example.test'
        );

        self::assertSame(201, $response->statusCode());
        $stored = json_decode(trim((string) file_get_contents($this->path)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'form_id' => 'cf7-123',
            'fields' => [
                'your-email' => 'ryo@example.test',
                'your-message' => 'Static works',
                'your-name' => 'Ryo',
                'your-subject' => 'Hello',
            ],
        ], $stored);
    }

    public function testCF7MarkupWithoutRecognizableNumericIdIsLeftUnchanged(): void
    {
        $processor = new FormProcessor([new ContactForm7Adapter()], 'https://forms.example.test/submit');
        $html = '<form action="/contact/#wpcf7" method="post" class="wpcf7-form"><input type="text" name="your-name"></form>';

        $rewritten = $processor->rewriteHtml($html);

        self::assertStringContainsString('action="/contact/#wpcf7"', $rewritten);
        self::assertStringContainsString('class="wpcf7-form"', $rewritten);
        self::assertStringNotContainsString('_wpss_form_id', $rewritten);
    }

    /**
     * @dataProvider unsupportedVersionProvider
     */
    public function testCF7MarkupWithoutSupportedVersionIsLeftUnchanged(?string $version): void
    {
        $versionInput = $version === null
            ? ''
            : '<input type="hidden" name="_wpcf7_version" value="' . $version . '">';
        $processor = new FormProcessor([new ContactForm7Adapter()], 'https://forms.example.test/submit');
        $html = '<form action="/contact/#wpcf7" method="post" class="wpcf7-form">'
            . '<input type="hidden" name="_wpcf7" value="123">'
            . $versionInput
            . '<input type="text" name="your-name"></form>';

        $rewritten = $processor->rewriteHtml($html);

        self::assertStringContainsString('action="/contact/#wpcf7"', $rewritten);
        self::assertStringContainsString('class="wpcf7-form"', $rewritten);
        self::assertStringNotContainsString('_wpss_form_id', $rewritten);
    }

    /** @return array<string, array{?string}> */
    public static function unsupportedVersionProvider(): array
    {
        return [
            'missing' => [null],
            'missing patch component' => ['6.0'],
            'unsupported major' => ['7.0.0'],
            'development suffix' => ['6.0.1-dev'],
        ];
    }

    public function testDuplicateCF7IdentityOrVersionInputsAreNotDetected(): void
    {
        foreach ([
            '<input type="hidden" name="_wpcf7" value="123"><input type="hidden" name="_wpcf7" value="124">'
                . '<input type="hidden" name="_wpcf7_version" value="6.0.1">',
            '<input type="hidden" name="_wpcf7" value="123">'
                . '<input type="hidden" name="_wpcf7_version" value="6.0.1"><input type="hidden" name="_wpcf7_version" value="6.0.2">',
        ] as $identityFields) {
            $document = new DOMDocument();
            self::assertTrue($document->loadHTML(
                '<form class="wpcf7-form">' . $identityFields . '<input name="your-name"></form>',
                LIBXML_NONET
            ));
            $form = $document->getElementsByTagName('form')->item(0);
            self::assertInstanceOf(DOMElement::class, $form);
            self::assertFalse((new ContactForm7Adapter())->supports($form));
        }
    }

    public function testUnsupportedCF7FileFieldFailsClosed(): void
    {
        $adapter = new ContactForm7Adapter();
        $document = new DOMDocument();
        $document->loadHTML('<form class="wpcf7-form"><input type="hidden" name="_wpcf7" value="123"><input type="hidden" name="_wpcf7_version" value="6.0.1"><input type="file" name="attachment"></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);

        $this->expectException(InvalidArgumentException::class);
        $adapter->extractSchema($form);
    }

    /**
     * @dataProvider unsupportedControlProvider
     */
    public function testUnsupportedCF7ControlsFailClosed(string $control): void
    {
        $adapter = new ContactForm7Adapter();
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML(
            '<form class="wpcf7-form">'
                . '<input type="hidden" name="_wpcf7" value="123">'
                . '<input type="hidden" name="_wpcf7_version" value="6.0.1">'
                . $control
                . '</form>',
            LIBXML_NONET
        ));
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);

        $this->expectException(InvalidArgumentException::class);
        $adapter->extractSchema($form);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedControlProvider(): array
    {
        return [
            'user-defined hidden field' => ['<input type="hidden" name="tracking-token" value="secret">'],
            'multi-select' => ['<select name="topics" multiple><option value="security">Security</option></select>'],
            'array-valued field' => ['<input type="text" name="topics[]">'],
        ];
    }

    private function fixtureDocument(): DOMDocument
    {
        $document = new DOMDocument();
        $fixture = file_get_contents(dirname(__DIR__) . '/Fixtures/contact-form-7-rendered.html');
        self::assertIsString($fixture);
        self::assertTrue($document->loadHTML($fixture, LIBXML_NONET));
        return $document;
    }

    private function controlByName(DOMElement $form, string $name): DOMElement
    {
        foreach ($form->getElementsByTagName('*') as $control) {
            if ($control instanceof DOMElement && $control->getAttribute('name') === $name) {
                return $control;
            }
        }

        self::fail('Expected control was not found: ' . $name);
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Forms;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Forms\FormProcessor;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;
use WPStaticSecure\Forms\SubmissionValidationException;

final class GenericHtmlFormAdapterTest extends TestCase
{
    public function testExplicitGenericFormIsDetectedAndRewritten(): void
    {
        $processor = new FormProcessor([new GenericHtmlFormAdapter()], 'https://forms.example.test/submit');
        $html = $processor->rewriteHtml('<form data-wpss-form="contact" action="https://wp.example/plugin-handler"><input name="email" type="email"><textarea name="message"></textarea><button type="submit">Send</button></form>');

        self::assertStringContainsString('action="https://forms.example.test/submit"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="_wpss_form_id" value="contact"', $html);
        self::assertStringNotContainsString('https://wp.example/plugin-handler', $html);
    }

    public function testUnmarkedFormIsLeftUntouched(): void
    {
        $processor = new FormProcessor([new GenericHtmlFormAdapter()], 'https://forms.example.test/submit');
        $html = $processor->rewriteHtml('<form action="/legacy"><input name="email"></form>');

        self::assertStringContainsString('action="/legacy"', $html);
        self::assertStringNotContainsString('_wpss_form_id', $html);
    }

    public function testSchemaRejectsMalformedIdentifierAndFieldNames(): void
    {
        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="../bad"><input name="email[]"></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);

        $this->expectException(InvalidArgumentException::class);
        $adapter->extractSchema($form);
    }

    public function testSubmissionValidationRejectsUnknownAndStructuredFields(): void
    {
        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="contact"><input name="email"><textarea name="message"></textarea></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        $definition = $adapter->extractSchema($form);

        $this->expectException(SubmissionValidationException::class);
        $adapter->validateSubmission($definition, [
            '_wpss_form_id' => 'contact',
            'email' => 'a@example.test',
            'unexpected' => ['nested'],
        ]);
    }

    public function testSubmissionIsNormalizedToAllowedFieldsOnly(): void
    {
        $adapter = new GenericHtmlFormAdapter();
        $document = new DOMDocument();
        $document->loadHTML('<form data-wpss-form="contact"><input name="email"><textarea name="message"></textarea></form>');
        $form = $document->getElementsByTagName('form')->item(0);
        self::assertInstanceOf(DOMElement::class, $form);
        $definition = $adapter->extractSchema($form);

        $submission = $adapter->validateSubmission($definition, [
            '_wpss_form_id' => 'contact',
            'message' => 'Hello',
            'email' => 'a@example.test',
        ]);

        self::assertSame([
            'form_id' => 'contact',
            'fields' => ['email' => 'a@example.test', 'message' => 'Hello'],
        ], $submission->toArray());
    }
}

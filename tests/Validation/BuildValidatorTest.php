<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WPStaticSecure\Validation\BuildValidator;

final class BuildValidatorTest extends TestCase
{
    private string $dist;

    protected function setUp(): void
    {
        $this->dist = sys_get_temp_dir() . '/wp-static-secure-validation-' . bin2hex(random_bytes(6));
        mkdir($this->dist . '/about', 0775, true);
        mkdir($this->dist . '/assets', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dist);
    }

    public function testReportsBrokenReferencesAndAuthoringOriginLeaksAsErrors(): void
    {
        file_put_contents($this->dist . '/index.html', <<<'HTML'
<!doctype html><html><body>
<a href="/about/">About</a>
<img src="/assets/missing.png">
<script src="https://wp.internal.example/private.js"></script>
</body></html>
HTML);
        file_put_contents($this->dist . '/about/index.html', '<h1>About</h1>');

        $report = (new BuildValidator($this->dist, 'https://wp.internal.example', 'https://www.example.com'))->validate();

        self::assertFalse($report->isSuccessful());
        self::assertSame(2, $report->errorCount());
        self::assertSame(['authoring_origin_leak', 'broken_local_reference'], array_values(array_unique(array_column($report->issues(), 'type'))));
    }

    public function testAcceptsExistingRelativeRootAndPublicOriginReferences(): void
    {
        file_put_contents($this->dist . '/index.html', '<a href="/about/">About</a><link rel="stylesheet" href="https://www.example.com/assets/site.css">');
        file_put_contents($this->dist . '/about/index.html', '<img src="../assets/logo.svg">');
        file_put_contents($this->dist . '/assets/site.css', 'body{background:url("logo.svg")}');
        file_put_contents($this->dist . '/assets/logo.svg', '<svg></svg>');

        $report = (new BuildValidator($this->dist, 'https://wp.internal.example', 'https://www.example.com'))->validate();

        self::assertTrue($report->isSuccessful());
        self::assertSame([], $report->issues());
    }

    public function testAcceptsCorrectlyRewrittenSupportedForm(): void
    {
        file_put_contents($this->dist . '/index.html', <<<'HTML'
<form data-wpss-form="contact" action="https://forms.example.com/submit" method="post" accept-charset="UTF-8">
<input type="hidden" name="_wpss_form_id" value="contact">
<input name="message">
</form>
HTML);

        $report = (new BuildValidator(
            $this->dist,
            'https://wp.internal.example',
            'https://www.example.com',
            'https://forms.example.com/submit'
        ))->validate();

        self::assertTrue($report->isSuccessful());
        self::assertSame([], $report->issues());
    }

    public function testReportsUnmarkedFormEvenWhenItTargetsConfiguredSubmissionEndpoint(): void
    {
        file_put_contents($this->dist . '/index.html', '<form action="https://forms.example.com/submit" method="post"><input name="message"></form>');

        $report = (new BuildValidator(
            $this->dist,
            'https://wp.internal.example',
            'https://www.example.com',
            'https://forms.example.com/submit'
        ))->validate();

        self::assertSame(1, $report->warningCount());
        self::assertSame('unsupported_dynamic_behavior', $report->issues()[0]['type']);
    }

    public function testReportsMarkedFormWithMalformedOrUnexpectedAction(): void
    {
        file_put_contents($this->dist . '/index.html', <<<'HTML'
<form data-wpss-form="contact" action="javascript:submit()" method="post">
<input type="hidden" name="_wpss_form_id" value="contact">
<input name="message">
</form>
HTML);

        $report = (new BuildValidator(
            $this->dist,
            'https://wp.internal.example',
            'https://www.example.com',
            'https://forms.example.com/submit'
        ))->validate();

        self::assertSame(1, $report->warningCount());
        self::assertSame('javascript:submit()', $report->issues()[0]['reference']);
    }

    public function testReportsMarkedFormWhenRewriteInvariantsAreIncomplete(): void
    {
        file_put_contents($this->dist . '/index.html', <<<'HTML'
<form data-wpss-form="contact" action="https://forms.example.com/submit" method="get">
<input type="hidden" name="_wpss_form_id" value="other-form">
<input name="message">
</form>
HTML);

        $report = (new BuildValidator(
            $this->dist,
            'https://wp.internal.example',
            'https://www.example.com',
            'https://forms.example.com/submit'
        ))->validate();

        self::assertSame(1, $report->warningCount());
        self::assertSame('unsupported_dynamic_behavior', $report->issues()[0]['type']);
    }

    public function testRejectsMalformedConfiguredSubmissionEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Submission endpoint must be an absolute HTTP(S) URL.');

        new BuildValidator($this->dist, 'https://wp.internal.example', 'https://www.example.com', '/submit');
    }

    public function testReportsUnsupportedDynamicBehaviorWithoutTreatingItAsStaticFallback(): void
    {
        file_put_contents($this->dist . '/index.html', '<form action="/contact/"><input name="message"></form><a href="/wp-login.php">Login</a>');

        $report = (new BuildValidator($this->dist, 'https://wp.internal.example', 'https://www.example.com'))->validate();

        self::assertTrue($report->isSuccessful());
        self::assertSame(0, $report->errorCount());
        self::assertSame(2, $report->warningCount());
        self::assertSame(['unsupported_dynamic_behavior'], array_values(array_unique(array_column($report->issues(), 'type'))));
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

<?php

declare(strict_types=1);

namespace WPStaticSecure\Validation;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;

final class BuildValidator
{
    private string $outputDirectory;
    private string $authoringOrigin;
    private string $publicOrigin;
    private ?string $submissionEndpoint;

    public function __construct(string $outputDirectory, string $authoringOrigin, string $publicOrigin, ?string $submissionEndpoint = null)
    {
        $root = realpath($outputDirectory);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException('Build validation requires an existing output directory.');
        }
        if ($submissionEndpoint !== null && !$this->isAbsoluteHttpUrl($submissionEndpoint)) {
            throw new InvalidArgumentException('Submission endpoint must be an absolute HTTP(S) URL.');
        }

        $this->outputDirectory = rtrim($root, DIRECTORY_SEPARATOR);
        $this->authoringOrigin = rtrim($authoringOrigin, '/');
        $this->publicOrigin = rtrim($publicOrigin, '/');
        $this->submissionEndpoint = $submissionEndpoint;
    }

    public function validate(): ValidationReport
    {
        $issues = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->outputDirectory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($this->outputDirectory) + 1));
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                $issues[] = $this->issue('error', 'unreadable_output', $relative, null, 'Generated output could not be read.');
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($this->isTextOutput($extension) && (stripos($contents, $this->authoringOrigin) !== false || $this->containsSchemeRelativeAuthoringOrigin($contents))) {
                $issues[] = $this->issue('error', 'authoring_origin_leak', $relative, $this->authoringOrigin, 'Generated output still references the private authoring origin.');
            }

            if (in_array($extension, ['html', 'htm'], true)) {
                array_push($issues, ...$this->validateHtml($contents, $relative));
            } elseif ($extension === 'css') {
                array_push($issues, ...$this->validateCss($contents, $relative));
            }
        }

        usort($issues, static function (array $a, array $b): int {
            return [$a['file'], $a['severity'], $a['type'], $a['reference'] ?? ''] <=> [$b['file'], $b['severity'], $b['type'], $b['reference'] ?? ''];
        });

        return new ValidationReport($issues);
    }

    /** @return list<array{severity:string,type:string,file:string,reference?:string,message:string}> */
    private function validateHtml(string $html, string $relativeFile): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [$this->issue('error', 'invalid_html', $relativeFile, null, 'Generated HTML could not be parsed for validation.')];
        }

        $issues = [];
        $xpath = new DOMXPath($document);
        foreach (['href', 'src', 'poster', 'data'] as $attribute) {
            foreach ($xpath->query('//*[@' . $attribute . ']') ?: [] as $node) {
                if ($node instanceof DOMElement) {
                    $this->checkReference($issues, $relativeFile, trim($node->getAttribute($attribute)));
                }
            }
        }

        foreach ($xpath->query('//img[@srcset] | //source[@srcset]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', trim($node->getAttribute('srcset'))) ?: [] as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
                $this->checkReference($issues, $relativeFile, $parts[0] ?? '');
            }
        }

        foreach ($xpath->query('//form') ?: [] as $node) {
            if (!$node instanceof DOMElement || $this->isSupportedForm($node)) {
                continue;
            }
            $action = trim($node->getAttribute('action'));
            $issues[] = $this->issue(
                'warning',
                'unsupported_dynamic_behavior',
                $relativeFile,
                $action !== '' ? $action : null,
                'Form behavior is dynamic and is not recognized as a supported WP Static Secure form.'
            );
        }

        return $issues;
    }

    /** @return list<array{severity:string,type:string,file:string,reference?:string,message:string}> */
    private function validateCss(string $css, string $relativeFile): array
    {
        $issues = [];
        if (preg_match_all('~url\(\s*([\'\"]?)(.*?)\1\s*\)~i', $css, $matches) !== false) {
            foreach ($matches[2] ?? [] as $reference) {
                $this->checkReference($issues, $relativeFile, trim((string) $reference));
            }
        }
        return $issues;
    }

    /** @param list<array{severity:string,type:string,file:string,reference?:string,message:string}> $issues */
    private function checkReference(array &$issues, string $relativeFile, string $reference): void
    {
        if ($reference === '' || str_starts_with($reference, '#') || preg_match('~^(?:data|mailto|tel|javascript):~i', $reference) === 1) {
            return;
        }

        $localPath = $this->localPathForReference($relativeFile, $reference);
        if ($localPath === null) {
            return;
        }

        if ($this->isUnsupportedDynamicPath($localPath)) {
            $issues[] = $this->issue(
                'warning',
                'unsupported_dynamic_behavior',
                $relativeFile,
                $reference,
                'Reference targets a WordPress dynamic endpoint that static delivery does not provide.'
            );
            return;
        }

        if (!is_file($this->outputDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $localPath))) {
            $issues[] = $this->issue('error', 'broken_local_reference', $relativeFile, $reference, 'Referenced local static output is missing.');
        }
    }

    private function isSupportedForm(DOMElement $form): bool
    {
        if ($this->submissionEndpoint === null || !$form->hasAttribute(GenericHtmlFormAdapter::FORM_ATTRIBUTE)) {
            return false;
        }

        $formId = trim($form->getAttribute(GenericHtmlFormAdapter::FORM_ATTRIBUTE));
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $formId) !== 1) {
            return false;
        }
        if (strcasecmp(trim($form->getAttribute('method')), 'post') !== 0) {
            return false;
        }
        if (trim($form->getAttribute('action')) !== $this->submissionEndpoint) {
            return false;
        }

        foreach ($form->getElementsByTagName('input') as $input) {
            if (!$input instanceof DOMElement) {
                continue;
            }
            if ($input->getAttribute('name') !== GenericHtmlFormAdapter::FORM_ID_FIELD) {
                continue;
            }
            return strcasecmp(trim($input->getAttribute('type')), 'hidden') === 0
                && $input->getAttribute('value') === $formId;
        }

        return false;
    }

    private function localPathForReference(string $relativeFile, string $reference): ?string
    {
        $reference = preg_replace('/[#?].*$/', '', $reference) ?? $reference;
        if ($reference === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $reference) === 1) {
            if (!$this->hasOrigin($reference, $this->publicOrigin)) {
                return null;
            }
            $path = (string) (parse_url($reference, PHP_URL_PATH) ?: '/');
        } elseif (str_starts_with($reference, '//')) {
            $publicHost = (string) parse_url($this->publicOrigin, PHP_URL_HOST);
            $referenceHost = (string) parse_url('https:' . $reference, PHP_URL_HOST);
            if (strcasecmp($publicHost, $referenceHost) !== 0) {
                return null;
            }
            $path = (string) (parse_url('https:' . $reference, PHP_URL_PATH) ?: '/');
        } elseif (str_starts_with($reference, '/')) {
            $path = $reference;
        } else {
            $directory = dirname('/' . $relativeFile);
            $path = ($directory === '/' ? '/' : $directory . '/') . $reference;
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            return 'index.html';
        }

        $normalized = implode('/', $segments);
        $last = $segments[array_key_last($segments)];
        if (str_ends_with($path, '/') || pathinfo($last, PATHINFO_EXTENSION) === '') {
            $normalized .= '/index.html';
        }

        return $normalized;
    }

    private function isUnsupportedDynamicPath(string $localPath): bool
    {
        return $localPath === 'wp-login.php'
            || $localPath === 'xmlrpc.php'
            || str_starts_with($localPath, 'wp-admin/')
            || str_starts_with($localPath, 'wp-json/');
    }

    private function hasOrigin(string $url, string $origin): bool
    {
        $urlParts = parse_url($url);
        $originParts = parse_url($origin);
        if ($urlParts === false || $originParts === false) {
            return false;
        }

        $urlPort = $urlParts['port'] ?? (($urlParts['scheme'] ?? '') === 'https' ? 443 : 80);
        $originPort = $originParts['port'] ?? (($originParts['scheme'] ?? '') === 'https' ? 443 : 80);

        return strcasecmp((string) ($urlParts['scheme'] ?? ''), (string) ($originParts['scheme'] ?? '')) === 0
            && strcasecmp((string) ($urlParts['host'] ?? ''), (string) ($originParts['host'] ?? '')) === 0
            && $urlPort === $originPort;
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function isTextOutput(string $extension): bool
    {
        return in_array($extension, ['html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'svg', 'map', 'webmanifest'], true);
    }

    private function containsSchemeRelativeAuthoringOrigin(string $contents): bool
    {
        $parts = parse_url($this->authoringOrigin);
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }
        $authority = '//' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return stripos($contents, $authority) !== false;
    }

    /** @return array{severity:string,type:string,file:string,reference?:string,message:string} */
    private function issue(string $severity, string $type, string $file, ?string $reference, string $message): array
    {
        $issue = ['severity' => $severity, 'type' => $type, 'file' => $file, 'message' => $message];
        if ($reference !== null) {
            $issue['reference'] = $reference;
        }
        return $issue;
    }
}

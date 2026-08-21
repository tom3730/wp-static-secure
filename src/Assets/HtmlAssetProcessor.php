<?php

declare(strict_types=1);

namespace WPStaticSecure\Assets;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use WPStaticSecure\Discovery\UrlNormalizer;

final class HtmlAssetProcessor
{
    private const DOWNLOAD_EXTENSIONS = [
        'pdf', 'zip', 'gz', 'tgz', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt',
    ];

    public function __construct(private ?UrlNormalizer $normalizer = null)
    {
        $this->normalizer ??= new UrlNormalizer();
    }

    public function process(string $html, string $documentUrl, string $authoringOrigin, string $publicOrigin): ProcessedContent
    {
        $documentUrl = $this->normalizer->normalize($documentUrl);
        $authoringOrigin = rtrim($authoringOrigin, '/');
        $publicOrigin = rtrim($publicOrigin, '/');

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new InvalidArgumentException('Unable to parse HTML document.');
        }

        $assets = [];
        $xpath = new DOMXPath($document);
        $selectors = [
            ['//script[@src]', 'src'],
            ['//img[@src]', 'src'],
            ['//source[@src]', 'src'],
            ['//video[@poster]', 'poster'],
            ['//audio[@src]', 'src'],
            ['//embed[@src]', 'src'],
            ['//object[@data]', 'data'],
            ['//input[translate(@type,"IMAGE","image")="image"][@src]', 'src'],
        ];

        foreach ($selectors as [$query, $attribute]) {
            foreach ($xpath->query($query) ?: [] as $node) {
                if ($node instanceof DOMElement) {
                    $this->processAssetAttribute($node, $attribute, $documentUrl, $authoringOrigin, $publicOrigin, $assets);
                }
            }
        }

        foreach ($xpath->query('//link[@href]') ?: [] as $node) {
            if ($node instanceof DOMElement && $this->isAssetLink($node)) {
                $this->processAssetAttribute($node, 'href', $documentUrl, $authoringOrigin, $publicOrigin, $assets);
            }
        }

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if ($node instanceof DOMElement && $this->isDownloadReference($node->getAttribute('href'))) {
                $this->processAssetAttribute($node, 'href', $documentUrl, $authoringOrigin, $publicOrigin, $assets);
            }
        }

        foreach ($xpath->query('//img[@srcset] | //source[@srcset]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->setAttribute('srcset', $this->processSrcset($node->getAttribute('srcset'), $documentUrl, $authoringOrigin, $publicOrigin, $assets));
            }
        }

        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $key = strtolower($node->getAttribute('property') ?: $node->getAttribute('name'));
            if (in_array($key, ['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src', 'msapplication-tileimage'], true)) {
                $this->processAssetAttribute($node, 'content', $documentUrl, $authoringOrigin, $publicOrigin, $assets);
            } elseif ($key === 'og:url') {
                $node->setAttribute('content', $this->rewriteAuthoringOrigin($node->getAttribute('content'), $authoringOrigin, $publicOrigin));
            }
        }

        foreach ($xpath->query('//link[contains(concat(" ", normalize-space(translate(@rel,"CANONICAL","canonical")), " "), " canonical ")][@href]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->setAttribute('href', $this->rewriteAuthoringOrigin($node->getAttribute('href'), $authoringOrigin, $publicOrigin));
            }
        }

        $body = $document->saveHTML();
        if ($body === false) {
            throw new InvalidArgumentException('Unable to serialize HTML document.');
        }

        $assets = array_values(array_unique($assets));
        sort($assets, SORT_STRING);
        return new ProcessedContent($body, $assets);
    }

    /** @param list<string> $assets */
    private function processAssetAttribute(DOMElement $node, string $attribute, string $documentUrl, string $authoringOrigin, string $publicOrigin, array &$assets): void
    {
        $value = trim($node->getAttribute($attribute));
        $resolved = $this->resolveInternalAsset($value, $documentUrl, $authoringOrigin);
        if ($resolved !== null) {
            $assets[] = $resolved;
        }
        $node->setAttribute($attribute, $this->rewriteAuthoringOrigin($value, $authoringOrigin, $publicOrigin));
    }

    /** @param list<string> $assets */
    private function processSrcset(string $srcset, string $documentUrl, string $authoringOrigin, string $publicOrigin, array &$assets): string
    {
        $candidates = preg_split('/\s*,\s*/', trim($srcset)) ?: [];
        $rewritten = [];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
            $url = $parts[0] ?? '';
            $descriptor = $parts[1] ?? '';
            $resolved = $this->resolveInternalAsset($url, $documentUrl, $authoringOrigin);
            if ($resolved !== null) {
                $assets[] = $resolved;
            }
            $url = $this->rewriteAuthoringOrigin($url, $authoringOrigin, $publicOrigin);
            $rewritten[] = $url . ($descriptor !== '' ? ' ' . $descriptor : '');
        }
        return implode(', ', $rewritten);
    }

    private function resolveInternalAsset(string $reference, string $documentUrl, string $authoringOrigin): ?string
    {
        if ($reference === '' || str_starts_with($reference, '#') || preg_match('~^(?:data|mailto|tel|javascript):~i', $reference) === 1) {
            return null;
        }
        try {
            $resolved = $this->normalizer->normalize($reference, $documentUrl);
        } catch (InvalidArgumentException) {
            return null;
        }
        return $this->originOf($resolved) === $authoringOrigin ? $resolved : null;
    }

    private function rewriteAuthoringOrigin(string $reference, string $authoringOrigin, string $publicOrigin): string
    {
        if ($reference === $authoringOrigin) {
            return $publicOrigin;
        }
        if (str_starts_with($reference, $authoringOrigin . '/')) {
            return $publicOrigin . substr($reference, strlen($authoringOrigin));
        }
        return $reference;
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    private function isAssetLink(DOMElement $node): bool
    {
        $tokens = preg_split('/\s+/', trim(strtolower($node->getAttribute('rel')))) ?: [];
        foreach (['stylesheet', 'icon', 'apple-touch-icon', 'manifest', 'preload'] as $assetRel) {
            if (in_array($assetRel, $tokens, true)) {
                return true;
            }
        }
        return false;
    }

    private function isDownloadReference(string $reference): bool
    {
        $path = parse_url($reference, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::DOWNLOAD_EXTENSIONS, true);
    }
}

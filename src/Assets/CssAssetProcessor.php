<?php

declare(strict_types=1);

namespace WPStaticSecure\Assets;

use InvalidArgumentException;
use WPStaticSecure\Discovery\UrlNormalizer;

final class CssAssetProcessor
{
    public function __construct(private ?UrlNormalizer $normalizer = null)
    {
        $this->normalizer ??= new UrlNormalizer();
    }

    public function process(string $css, string $stylesheetUrl, string $authoringOrigin, string $publicOrigin): ProcessedContent
    {
        $stylesheetUrl = $this->normalizer->normalize($stylesheetUrl);
        $authoringOrigin = rtrim($authoringOrigin, '/');
        $publicOrigin = rtrim($publicOrigin, '/');
        $assets = [];

        $body = preg_replace_callback(
            '~url\(\s*([\'\"]?)(.*?)\1\s*\)~i',
            function (array $match) use ($stylesheetUrl, $authoringOrigin, $publicOrigin, &$assets): string {
                $reference = trim($match[2]);
                if ($reference === '' || str_starts_with($reference, '#') || preg_match('~^(?:data|javascript):~i', $reference) === 1) {
                    return $match[0];
                }

                try {
                    $resolved = $this->normalizer->normalize($reference, $stylesheetUrl);
                } catch (InvalidArgumentException) {
                    return $match[0];
                }

                if ($this->originOf($resolved) === $authoringOrigin) {
                    $assets[] = $resolved;
                }

                $rewritten = $reference;
                if ($reference === $authoringOrigin) {
                    $rewritten = $publicOrigin;
                } elseif (str_starts_with($reference, $authoringOrigin . '/')) {
                    $rewritten = $publicOrigin . substr($reference, strlen($authoringOrigin));
                }

                return 'url(' . $match[1] . $rewritten . $match[1] . ')';
            },
            $css
        );

        if ($body === null) {
            throw new InvalidArgumentException('Unable to process CSS URLs.');
        }

        $assets = array_values(array_unique($assets));
        sort($assets, SORT_STRING);
        return new ProcessedContent($body, $assets);
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
}

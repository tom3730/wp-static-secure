<?php

declare(strict_types=1);

namespace WPStaticSecure\Discovery;

use InvalidArgumentException;

final class UrlDiscovery
{
    public function __construct(
        private CrawlScope $scope,
        private ?UrlNormalizer $normalizer = null
    ) {
        $this->normalizer ??= new UrlNormalizer();
    }

    /**
     * Normalize and classify URL candidates discovered from seeds, sitemaps, or later crawled pages.
     *
     * @param iterable<string> $urls
     */
    public function discover(iterable $urls, ?string $baseUrl = null): DiscoveryResult
    {
        $crawl = [];
        $external = [];
        $outOfScope = [];
        $invalid = [];
        $baseUrl ??= $this->scope->origin() . '/';

        foreach ($urls as $candidate) {
            try {
                $normalized = $this->normalizer->normalize($candidate, $baseUrl);
            } catch (InvalidArgumentException) {
                $invalid[$candidate] = true;
                continue;
            }

            switch ($this->scope->classify($normalized)) {
                case CrawlScope::INTERNAL:
                    $crawl[$normalized] = true;
                    break;
                case CrawlScope::EXTERNAL:
                    $external[$normalized] = true;
                    break;
                case CrawlScope::OUT_OF_SCOPE:
                    $outOfScope[$normalized] = true;
                    break;
            }
        }

        $crawlUrls = array_keys($crawl);
        $externalUrls = array_keys($external);
        $outOfScopeUrls = array_keys($outOfScope);
        $invalidUrls = array_keys($invalid);
        sort($crawlUrls, SORT_STRING);
        sort($externalUrls, SORT_STRING);
        sort($outOfScopeUrls, SORT_STRING);
        sort($invalidUrls, SORT_STRING);

        return new DiscoveryResult($crawlUrls, $externalUrls, $outOfScopeUrls, $invalidUrls);
    }
}

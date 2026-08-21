<?php

declare(strict_types=1);

namespace WPStaticSecure\Fetching;

use InvalidArgumentException;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Discovery\UrlNormalizer;

final class PageExporter
{
    public function __construct(
        private HttpFetcher $fetcher,
        private CrawlScope $scope,
        private FilesystemPageStore $store,
        private ?OutputPathMapper $mapper = null,
        private ?UrlNormalizer $normalizer = null
    ) {
        $this->mapper ??= new OutputPathMapper();
        $this->normalizer ??= new UrlNormalizer();
    }

    /**
     * @param iterable<string> $urls
     * @return list<array<string, mixed>>
     */
    public function export(iterable $urls): array
    {
        $results = [];
        $manifest = [];

        foreach ($urls as $candidate) {
            try {
                $url = $this->normalizer->normalize($candidate);
            } catch (InvalidArgumentException $e) {
                $results[] = ['url' => $candidate, 'status' => 'unsafe_url', 'message' => $e->getMessage()];
                continue;
            }

            if ($this->scope->classify($url) !== CrawlScope::INTERNAL) {
                $results[] = ['url' => $url, 'status' => 'out_of_scope'];
                continue;
            }

            try {
                $outputPath = $this->mapper->map($url);
            } catch (InvalidArgumentException $e) {
                $results[] = ['url' => $url, 'status' => 'unsafe_path', 'message' => $e->getMessage()];
                continue;
            }

            try {
                $response = $this->fetcher->fetch($url);
            } catch (FetchException $e) {
                $results[] = ['url' => $url, 'status' => 'network_error', 'message' => $e->getMessage()];
                continue;
            }

            $statusCode = $response->statusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->store->write($outputPath, $response->body());
                $entry = [
                    'url' => $url,
                    'output_path' => $outputPath,
                    'status_code' => $statusCode,
                    'content_type' => $response->firstHeader('content-type'),
                ];
                $manifest[] = $entry;
                $results[] = ['url' => $url, 'status' => 'written', 'output_path' => $outputPath, 'status_code' => $statusCode];
                continue;
            }

            if ($statusCode >= 300 && $statusCode < 400) {
                $results[] = [
                    'url' => $url,
                    'status' => 'redirect',
                    'status_code' => $statusCode,
                    'location' => $response->firstHeader('location'),
                ];
                continue;
            }

            $results[] = ['url' => $url, 'status' => 'http_error', 'status_code' => $statusCode];
        }

        $this->store->writeManifest($manifest);

        return $results;
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Assets;

use InvalidArgumentException;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Discovery\UrlNormalizer;
use WPStaticSecure\Fetching\FetchException;
use WPStaticSecure\Fetching\FilesystemPageStore;
use WPStaticSecure\Fetching\HttpFetcher;
use WPStaticSecure\Fetching\OutputPathMapper;

final class AssetExporter
{
    public function __construct(
        private HttpFetcher $fetcher,
        private CrawlScope $scope,
        private FilesystemPageStore $store,
        private ?CssAssetProcessor $cssProcessor = null,
        private ?OutputPathMapper $mapper = null,
        private ?UrlNormalizer $normalizer = null
    ) {
        $this->cssProcessor ??= new CssAssetProcessor();
        $this->mapper ??= new OutputPathMapper();
        $this->normalizer ??= new UrlNormalizer();
    }

    /**
     * @param iterable<string> $assetUrls
     * @return list<array<string, mixed>>
     */
    public function export(iterable $assetUrls, string $publicOrigin): array
    {
        $queue = [];
        foreach ($assetUrls as $url) {
            $queue[] = $url;
        }

        $results = [];
        $seenUrls = [];
        $seenPaths = [];

        while ($queue !== []) {
            $candidate = array_shift($queue);
            try {
                $url = $this->normalizer->normalize((string) $candidate);
            } catch (InvalidArgumentException $e) {
                $results[] = ['kind' => 'asset', 'url' => (string) $candidate, 'status' => 'unsafe_url', 'message' => $e->getMessage()];
                continue;
            }

            if (isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;

            if ($this->scope->classify($url) !== CrawlScope::INTERNAL) {
                $results[] = ['kind' => 'asset', 'url' => $url, 'status' => 'out_of_scope'];
                continue;
            }

            try {
                $outputPath = $this->mapAssetPath($url);
            } catch (InvalidArgumentException $e) {
                $results[] = ['kind' => 'asset', 'url' => $url, 'status' => 'unsafe_path', 'message' => $e->getMessage()];
                continue;
            }

            if (isset($seenPaths[$outputPath]) && $seenPaths[$outputPath] !== $url) {
                $results[] = ['kind' => 'asset', 'url' => $url, 'status' => 'path_collision', 'output_path' => $outputPath];
                continue;
            }
            $seenPaths[$outputPath] = $url;

            try {
                $response = $this->fetcher->fetch($url);
            } catch (FetchException $e) {
                $results[] = ['kind' => 'asset', 'url' => $url, 'status' => 'network_error', 'message' => $e->getMessage()];
                continue;
            }

            $statusCode = $response->statusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $results[] = ['kind' => 'asset', 'url' => $url, 'status' => 'http_error', 'status_code' => $statusCode];
                continue;
            }

            $body = $response->body();
            $contentType = strtolower((string) $response->firstHeader('content-type'));
            if (str_starts_with($contentType, 'text/css') || strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) === 'css') {
                $processed = $this->cssProcessor->process($body, $url, $this->scope->origin(), $publicOrigin);
                $body = $processed->body();
                foreach ($processed->assetUrls() as $nestedUrl) {
                    $queue[] = $nestedUrl;
                }
            }

            $this->store->write($outputPath, $body);
            $results[] = [
                'kind' => 'asset',
                'url' => $url,
                'status' => 'written',
                'output_path' => $outputPath,
                'status_code' => $statusCode,
            ];
        }

        return $results;
    }

    private function mapAssetPath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Asset output mapping requires an absolute URL.');
        }

        $withoutQuery = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $withoutQuery .= ':' . $parts['port'];
        }
        $withoutQuery .= $parts['path'] ?? '/';

        return $this->mapper->map($withoutQuery);
    }
}

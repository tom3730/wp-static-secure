<?php

declare(strict_types=1);

namespace WPStaticSecure\Fetching;

use InvalidArgumentException;
use WPStaticSecure\Assets\AssetExporter;
use WPStaticSecure\Assets\HtmlAssetProcessor;
use WPStaticSecure\Discovery\CrawlScope;
use WPStaticSecure\Discovery\UrlNormalizer;
use WPStaticSecure\Forms\FormProcessor;

final class PageExporter
{
    public function __construct(
        private HttpFetcher $fetcher,
        private CrawlScope $scope,
        private FilesystemPageStore $store,
        private ?OutputPathMapper $mapper = null,
        private ?UrlNormalizer $normalizer = null,
        private ?HtmlAssetProcessor $htmlProcessor = null,
        private ?AssetExporter $assetExporter = null,
        private ?string $publicOrigin = null,
        private ?FormProcessor $formProcessor = null
    ) {
        $this->mapper ??= new OutputPathMapper();
        $this->normalizer ??= new UrlNormalizer();

        if (($this->htmlProcessor !== null || $this->assetExporter !== null) && $this->publicOrigin === null) {
            throw new InvalidArgumentException('Public origin is required when asset processing is enabled.');
        }
        if (($this->htmlProcessor === null) !== ($this->assetExporter === null)) {
            throw new InvalidArgumentException('HTML and asset processors must be enabled together.');
        }
    }

    /**
     * @param iterable<string> $urls
     * @return list<array<string, mixed>>
     */
    public function export(iterable $urls): array
    {
        $results = [];
        $manifest = [];
        $assetUrls = [];

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
                $body = $response->body();
                $contentType = strtolower((string) $response->firstHeader('content-type'));

                if (str_starts_with($contentType, 'text/html')) {
                    if ($this->htmlProcessor !== null) {
                        try {
                            $processed = $this->htmlProcessor->process(
                                $body,
                                $url,
                                $this->scope->origin(),
                                (string) $this->publicOrigin
                            );
                        } catch (InvalidArgumentException $e) {
                            $results[] = ['url' => $url, 'status' => 'processing_error', 'message' => $e->getMessage()];
                            continue;
                        }
                        $body = $processed->body();
                        foreach ($processed->assetUrls() as $assetUrl) {
                            $assetUrls[] = $assetUrl;
                        }
                    }

                    if ($this->formProcessor !== null) {
                        try {
                            $body = $this->formProcessor->rewriteHtml($body);
                        } catch (InvalidArgumentException $e) {
                            $results[] = ['url' => $url, 'status' => 'processing_error', 'message' => $e->getMessage()];
                            continue;
                        }
                    }
                }

                $this->store->write($outputPath, $body);
                $manifest[] = [
                    'output_path' => $outputPath,
                    'status_code' => $statusCode,
                    'content_type' => $response->firstHeader('content-type'),
                ];
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

        if ($this->assetExporter !== null && $assetUrls !== []) {
            array_push($results, ...$this->assetExporter->export($assetUrls, (string) $this->publicOrigin));
        }

        $this->store->writeManifest($manifest);

        return $results;
    }
}

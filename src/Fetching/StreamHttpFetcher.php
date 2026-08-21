<?php

declare(strict_types=1);

namespace WPStaticSecure\Fetching;

final class StreamHttpFetcher implements HttpFetcher
{
    public function __construct(private float $timeoutSeconds = 10.0)
    {
    }

    public function fetch(string $url): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: WP-Static-Secure/0.x\r\nAccept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();
            throw new FetchException('HTTP fetch failed for ' . $url . ($error !== null ? ': ' . $error['message'] : '.'));
        }

        $responseHeaders = $http_response_header ?? [];
        if ($responseHeaders === [] || preg_match('~^HTTP/\S+\s+(\d{3})\b~', $responseHeaders[0], $match) !== 1) {
            throw new FetchException('HTTP fetch returned no valid status line for ' . $url . '.');
        }

        $headers = [];
        foreach (array_slice($responseHeaders, 1) as $line) {
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $position)));
            $value = trim(substr($line, $position + 1));
            $headers[$name][] = $value;
        }

        return new HttpResponse((int) $match[1], $headers, $body);
    }
}

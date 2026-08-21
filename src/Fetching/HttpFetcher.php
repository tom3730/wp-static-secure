<?php

declare(strict_types=1);

namespace WPStaticSecure\Fetching;

interface HttpFetcher
{
    /** @throws FetchException */
    public function fetch(string $url): HttpResponse;
}

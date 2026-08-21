<?php

declare(strict_types=1);

namespace WPStaticSecure\Assets;

final class ProcessedContent
{
    /** @param list<string> $assetUrls */
    public function __construct(private string $body, private array $assetUrls)
    {
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function assetUrls(): array
    {
        return $this->assetUrls;
    }
}

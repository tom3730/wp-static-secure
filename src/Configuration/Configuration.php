<?php

declare(strict_types=1);

namespace WPStaticSecure\Configuration;

final class Configuration
{
    public function __construct(
        private Origin $authoringOrigin,
        private Origin $publicOrigin,
        private OutputDirectory $outputDirectory
    ) {
    }

    public function authoringOrigin(): Origin
    {
        return $this->authoringOrigin;
    }

    public function publicOrigin(): Origin
    {
        return $this->publicOrigin;
    }

    public function outputDirectory(): OutputDirectory
    {
        return $this->outputDirectory;
    }
}

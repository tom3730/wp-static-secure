<?php

declare(strict_types=1);

namespace WPStaticSecure\Discovery;

final class DiscoveryResult
{
    /**
     * @param list<string> $crawlUrls
     * @param list<string> $externalUrls
     * @param list<string> $outOfScopeUrls
     * @param list<string> $invalidUrls
     */
    public function __construct(
        private array $crawlUrls,
        private array $externalUrls,
        private array $outOfScopeUrls,
        private array $invalidUrls
    ) {
    }

    /** @return list<string> */
    public function crawlUrls(): array
    {
        return $this->crawlUrls;
    }

    /** @return list<string> */
    public function externalUrls(): array
    {
        return $this->externalUrls;
    }

    /** @return list<string> */
    public function outOfScopeUrls(): array
    {
        return $this->outOfScopeUrls;
    }

    /** @return list<string> */
    public function invalidUrls(): array
    {
        return $this->invalidUrls;
    }
}

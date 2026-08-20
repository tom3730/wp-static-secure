<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Configuration\Configuration;
use WPStaticSecure\Configuration\Origin;
use WPStaticSecure\Configuration\OutputDirectory;

final class ConfigurationTest extends TestCase
{
    public function test_exposes_one_normalized_configuration_object(): void
    {
        $configuration = new Configuration(
            new Origin('https://wp.internal.example/'),
            new Origin('https://www.example.com/'),
            new OutputDirectory('/tmp/wp-static-secure/dist/')
        );

        self::assertSame('https://wp.internal.example', $configuration->authoringOrigin()->value());
        self::assertSame('https://www.example.com', $configuration->publicOrigin()->value());
        self::assertSame('/tmp/wp-static-secure/dist', $configuration->outputDirectory()->path());
    }
}

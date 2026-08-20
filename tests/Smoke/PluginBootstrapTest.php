<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Plugin;

final class PluginBootstrapTest extends TestCase
{
    public function test_plugin_bootstrap_loads_without_fatal_error(): void
    {
        require_once dirname(__DIR__, 2) . '/wp-static-secure.php';

        self::assertTrue(class_exists(Plugin::class));
        self::assertSame('0.1.0-dev', Plugin::VERSION);
    }
}

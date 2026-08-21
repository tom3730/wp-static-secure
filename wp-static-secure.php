<?php
/**
 * Plugin Name: WP Static Secure
 * Description: Static publishing foundation for a restricted WordPress authoring environment.
 * Version: 0.1.0-dev
 * Requires PHP: 8.1
 * Author: WP Static Secure contributors
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if (! is_readable($autoload)) {
    return;
}

require_once $autoload;

register_activation_hook(__FILE__, [WPStaticSecure\Plugin::class, 'activate']);
WPStaticSecure\Plugin::boot();

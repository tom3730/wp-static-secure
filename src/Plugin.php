<?php

declare(strict_types=1);

namespace WPStaticSecure;

use WPStaticSecure\Forms\WordPressSubmissionStore;
use WPStaticSecure\WordPress\SubmissionInbox;
use WPStaticSecure\WordPress\SubmissionTable;

final class Plugin
{
    public const VERSION = '0.1.0-dev';

    public static function boot(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        SubmissionTable::maybeInstall();

        global $wpdb;
        if (!is_object($wpdb)) {
            return;
        }

        (new SubmissionInbox(new WordPressSubmissionStore($wpdb)))->register();
    }

    public static function activate(): void
    {
        SubmissionTable::install();
    }
}

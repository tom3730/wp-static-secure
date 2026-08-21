<?php

declare(strict_types=1);

namespace WPStaticSecure\WordPress;

use RuntimeException;
use WPStaticSecure\Forms\WordPressSubmissionStore;

final class SubmissionTable
{
    public const SCHEMA_VERSION = 1;
    public const OPTION_NAME = 'wpss_submission_schema_version';

    public static function maybeInstall(): void
    {
        if (!function_exists('get_option') || (int) get_option(self::OPTION_NAME, 0) >= self::SCHEMA_VERSION) {
            return;
        }
        self::install();
    }

    public static function install(): void
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new RuntimeException('WordPress database is unavailable.');
        }

        $store = new WordPressSubmissionStore($wpdb);
        $charset = method_exists($wpdb, 'get_charset_collate') ? (string) $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$store->tableName()} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id varchar(64) NOT NULL,
            fields_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_created (status, created_at),
            KEY form_created (form_id, created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::OPTION_NAME, self::SCHEMA_VERSION, false);
    }
}

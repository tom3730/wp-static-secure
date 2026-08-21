<?php

declare(strict_types=1);

namespace WPStaticSecure\WordPress;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WPStaticSecure\Forms\WordPressSubmissionStore;

function current_user_can(string $capability): bool
{
    return (bool) ($GLOBALS['wpss_test_can_manage'] ?? false);
}

function wp_die(string $message, string $title = '', array $args = []): never
{
    throw new RuntimeException(strip_tags($message));
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? '';
}

function wp_unslash(string $value): string
{
    return $value;
}

function admin_url(string $path): string
{
    return 'https://wp.example/wp-admin/' . ltrim($path, '/');
}

function esc_html__(string $text, string $domain): string
{
    return esc_html($text);
}

function __(string $text, string $domain): string
{
    return $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr(string $text): string
{
    return esc_html($text);
}

function esc_url(string $url): string
{
    return esc_html($url);
}

function add_query_arg(string $key, string $value, string $url): string
{
    return $url . '&' . rawurlencode($key) . '=' . rawurlencode($value);
}

function wp_nonce_field(string $action): void
{
    echo '<input type="hidden" name="_wpnonce" value="test">';
}

function selected(string $current, string $value, bool $echo = true): string
{
    return $current === $value ? ' selected="selected"' : '';
}

function submit_button(string $text, string $type, string $name, bool $wrap): void
{
    echo '<button type="submit">' . esc_html($text) . '</button>';
}

final class SubmissionInboxTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wpss_test_can_manage'] = false;
        $_GET = [];
    }

    public function testUnauthorizedUserCannotRenderInbox(): void
    {
        $GLOBALS['wpss_test_can_manage'] = false;
        $inbox = new SubmissionInbox($this->storeWithRows([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not allowed');
        $inbox->renderPage();
    }

    public function testAdminOutputEscapesStoredSubmissionValues(): void
    {
        $GLOBALS['wpss_test_can_manage'] = true;
        $inbox = new SubmissionInbox($this->storeWithRows([
            (object) [
                'id' => 1,
                'form_id' => 'contact',
                'fields_json' => json_encode(['message' => '<script>alert(1)</script>'], JSON_THROW_ON_ERROR),
                'status' => 'new',
                'created_at' => '2026-08-21 06:00:00',
            ],
        ]));

        ob_start();
        $inbox->renderPage();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /** @param list<object> $rows */
    private function storeWithRows(array $rows): WordPressSubmissionStore
    {
        $wpdb = new class ($rows) {
            public string $prefix = 'wp_';

            /** @param list<object> $rows */
            public function __construct(private array $rows)
            {
            }

            public function prepare(string $query, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    $query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
                }
                return $query;
            }

            /** @return list<object> */
            public function get_results(string $query): array
            {
                return $this->rows;
            }
        };
        return new WordPressSubmissionStore($wpdb);
    }
}

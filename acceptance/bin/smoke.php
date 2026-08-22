<?php

declare(strict_types=1);

use WPStaticSecure\Forms\ContactForm7Adapter;
use WPStaticSecure\Forms\FormProcessor;
use WPStaticSecure\Forms\GenericHtmlFormAdapter;
use WPStaticSecure\Forms\WordPressSubmissionStore;

$fail = static function (string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); };
$ok = static function (string $message): void { echo "PASS: {$message}\n"; };

$expectedVersion = ltrim((string) getenv('WPS_RELEASE_TAG'), 'v');
WPStaticSecure\Plugin::VERSION === $expectedVersion ? $ok('release class version') : $fail('release class version mismatch');

$plugins = (array) get_option('active_plugins', []);
in_array('wp-static-secure/wp-static-secure.php', $plugins, true) ? $ok('WP Static Secure active') : $fail('WP Static Secure inactive');
in_array('contact-form-7/wp-contact-form-7.php', $plugins, true) ? $ok('Contact Form 7 active') : $fail('Contact Form 7 inactive');

$theme = wp_get_theme();
$theme->get_stylesheet() === getenv('WPS_THEME') ? $ok('pinned theme active') : $fail('unexpected theme');
$theme->get('Version') === getenv('WPS_THEME_VERSION') ? $ok('pinned theme version') : $fail('unexpected theme version');
defined('WPCF7_VERSION') && WPCF7_VERSION === getenv('WPS_CF7_VERSION') ? $ok('pinned Contact Form 7 version') : $fail('unexpected Contact Form 7 version');

foreach (['acceptance-about', 'generic-form', 'cf7-form'] as $slug) {
    get_page_by_path($slug) instanceof WP_Post ? $ok("fixture {$slug}") : $fail("missing fixture {$slug}");
}
get_page_by_path('acceptance-post', OBJECT, 'post') instanceof WP_Post ? $ok('fixture acceptance-post') : $fail('missing fixture acceptance-post');

$attachments = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1]);
$mimes = array_map(static fn (WP_Post $p): string => (string) get_post_mime_type($p), $attachments);
in_array('image/png', $mimes, true) ? $ok('image fixture') : $fail('missing PNG fixture');
in_array('application/pdf', $mimes, true) ? $ok('PDF fixture') : $fail('missing PDF fixture');

$endpoint = 'https://forms.example.invalid/submit';
$generic = new FormProcessor([new GenericHtmlFormAdapter()], $endpoint);
$out = $generic->rewriteHtml('<form data-wpss-form="acceptance-contact"><input name="email" type="email"><textarea name="message"></textarea></form>');
(str_contains($out, 'action="' . $endpoint . '"') && str_contains($out, 'name="_wpss_form_id"') && str_contains($out, 'method="post"')) ? $ok('generic form rewrite') : $fail('generic form rewrite');

$cf7 = new FormProcessor([new ContactForm7Adapter()], $endpoint);
$cf7Html = '<form class="wpcf7-form" action="/old"><input type="hidden" name="_wpcf7" value="123"><input type="hidden" name="_wpcf7_version" value="' . esc_attr((string) getenv('WPS_CF7_VERSION')) . '"><input name="your-email" type="email" aria-required="true"><textarea name="your-message"></textarea></form>';
$cf7Out = $cf7->rewriteHtml($cf7Html);
(str_contains($cf7Out, 'data-wpss-form="cf7-123"') && !str_contains($cf7Out, 'name="_wpcf7"') && str_contains($cf7Out, 'required')) ? $ok('conservative CF7 rewrite') : $fail('CF7 rewrite');

global $wpdb;
$store = new WordPressSubmissionStore($wpdb);
$table = $store->tableName();
$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table ? $ok('Submission Inbox table') : $fail('Submission Inbox table missing');
$rows = $store->list(null, 10);
count($rows) > 0 ? $ok('Submission Inbox persistence') : $fail('Submission Inbox empty');

$cf7Page = get_page_by_path('cf7-form');
$rendered = $cf7Page instanceof WP_Post ? apply_filters('the_content', $cf7Page->post_content) : '';
str_contains($rendered, 'wpcf7-form') ? $ok('CF7 fixture renders server-side form') : $fail('CF7 fixture did not render');

echo "All release acceptance checks passed.\n";

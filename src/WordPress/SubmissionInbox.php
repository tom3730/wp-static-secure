<?php

declare(strict_types=1);

namespace WPStaticSecure\WordPress;

use InvalidArgumentException;
use WPStaticSecure\Forms\StoredSubmission;
use WPStaticSecure\Forms\SubmissionStatus;
use WPStaticSecure\Forms\WordPressSubmissionStore;

final class SubmissionInbox
{
    public const CAPABILITY = 'manage_options';
    public const PAGE_SLUG = 'wp-static-secure-submissions';
    public const UPDATE_ACTION = 'wpss_update_submission_status';

    public function __construct(private WordPressSubmissionStore $store)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::UPDATE_ACTION, [$this, 'handleStatusUpdate']);
    }

    public function registerMenu(): void
    {
        add_management_page(
            'WP Static Secure Submissions',
            'Submission Inbox',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $this->requireCapability();

        $status = null;
        if (isset($_GET['status'])) {
            $candidate = sanitize_key(wp_unslash((string) $_GET['status']));
            if (SubmissionStatus::isValid($candidate)) {
                $status = $candidate;
            }
        }

        $submissions = $this->store->list($status);
        $baseUrl = admin_url('tools.php?page=' . self::PAGE_SLUG);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Submission Inbox', 'wp-static-secure') . '</h1>';
        echo '<p>' . esc_html__('Form submissions are stored application data. Notification delivery is not required for persistence.', 'wp-static-secure') . '</p>';
        echo '<p>';
        $this->filterLink($baseUrl, null, $status, __('All', 'wp-static-secure'));
        foreach (SubmissionStatus::all() as $filter) {
            echo ' | ';
            $this->filterLink($baseUrl, $filter, $status, $filter);
        }
        echo '</p>';

        echo '<table class="widefat fixed striped"><thead><tr>';
        foreach (['Time (UTC)', 'Form', 'Fields', 'Status'] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($submissions === []) {
            echo '<tr><td colspan="4">' . esc_html__('No submissions found.', 'wp-static-secure') . '</td></tr>';
        }

        foreach ($submissions as $submission) {
            $this->renderSubmission($submission);
        }

        echo '</tbody></table></div>';
    }

    public function handleStatusUpdate(): void
    {
        $this->requireCapability();

        $id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash((string) $_POST['status'])) : '';
        if ($id < 1 || !SubmissionStatus::isValid($status)) {
            wp_die(esc_html__('Invalid submission status update.', 'wp-static-secure'), '', ['response' => 400]);
        }

        check_admin_referer(self::UPDATE_ACTION . '_' . $id);
        $this->store->updateStatus($id, $status);

        wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE_SLUG));
        exit;
    }

    private function requireCapability(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to view form submissions.', 'wp-static-secure'), '', ['response' => 403]);
        }
    }

    private function filterLink(string $baseUrl, ?string $filter, ?string $active, string $label): void
    {
        $url = $filter === null ? $baseUrl : add_query_arg('status', $filter, $baseUrl);
        $style = $filter === $active ? 'font-weight:600' : '';
        echo '<a href="' . esc_url($url) . '" style="' . esc_attr($style) . '">' . esc_html($label) . '</a>';
    }

    private function renderSubmission(StoredSubmission $submission): void
    {
        echo '<tr>';
        echo '<td>' . esc_html($submission->createdAt()) . '</td>';
        echo '<td><code>' . esc_html($submission->formId()) . '</code></td>';
        echo '<td><dl>';
        foreach ($submission->fields() as $name => $value) {
            echo '<dt><strong>' . esc_html($name) . '</strong></dt><dd style="white-space:pre-wrap">' . esc_html($value) . '</dd>';
        }
        echo '</dl></td>';
        echo '<td>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::UPDATE_ACTION) . '">';
        echo '<input type="hidden" name="submission_id" value="' . esc_attr((string) $submission->id()) . '">';
        wp_nonce_field(self::UPDATE_ACTION . '_' . $submission->id());
        echo '<select name="status">';
        foreach (SubmissionStatus::all() as $status) {
            echo '<option value="' . esc_attr($status) . '"' . selected($submission->status(), $status, false) . '>' . esc_html($status) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Update', 'wp-static-secure'), 'secondary small', 'submit', false);
        echo '</form></td></tr>';
    }
}

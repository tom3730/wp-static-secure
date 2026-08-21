<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use InvalidArgumentException;
use RuntimeException;

final class WordPressSubmissionStore implements SubmissionStore
{
    private string $tableName;

    public function __construct(private object $wpdb)
    {
        $prefix = (string) ($wpdb->prefix ?? '');
        $this->tableName = $prefix . 'wpss_submissions';
        if (preg_match('/^[A-Za-z0-9_]+$/', $this->tableName) !== 1) {
            throw new InvalidArgumentException('WordPress submission table name is invalid.');
        }
    }

    public function save(Submission $submission): void
    {
        $json = json_encode($submission->fields(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $result = $this->wpdb->insert(
            $this->tableName,
            [
                'form_id' => $submission->formId(),
                'fields_json' => $json,
                'status' => SubmissionStatus::NEW,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s']
        );
        if ($result === false) {
            throw new RuntimeException('Unable to persist form submission.');
        }
    }

    /** @return list<StoredSubmission> */
    public function list(?string $status = null, int $limit = 100): array
    {
        if ($status !== null && !SubmissionStatus::isValid($status)) {
            throw new InvalidArgumentException('Submission status filter is invalid.');
        }
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Submission list limit must be between 1 and 500.');
        }

        if ($status === null) {
            $sql = $this->wpdb->prepare(
                "SELECT id, form_id, fields_json, status, created_at FROM {$this->tableName} ORDER BY created_at DESC, id DESC LIMIT %d",
                $limit
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT id, form_id, fields_json, status, created_at FROM {$this->tableName} WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d",
                $status,
                $limit
            );
        }

        $rows = $this->wpdb->get_results($sql);
        if (!is_array($rows)) {
            throw new RuntimeException('Unable to load form submissions.');
        }

        return array_map(fn (object $row): StoredSubmission => $this->hydrate($row), $rows);
    }

    public function find(int $id): ?StoredSubmission
    {
        if ($id < 1) {
            return null;
        }
        $sql = $this->wpdb->prepare(
            "SELECT id, form_id, fields_json, status, created_at FROM {$this->tableName} WHERE id = %d",
            $id
        );
        $row = $this->wpdb->get_row($sql);
        return is_object($row) ? $this->hydrate($row) : null;
    }

    public function updateStatus(int $id, string $status): bool
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Submission id must be positive.');
        }
        if (!SubmissionStatus::isValid($status)) {
            throw new InvalidArgumentException('Submission status is invalid.');
        }

        $result = $this->wpdb->update(
            $this->tableName,
            ['status' => $status],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
        if ($result === false) {
            throw new RuntimeException('Unable to update submission status.');
        }
        return $result > 0;
    }

    public function tableName(): string
    {
        return $this->tableName;
    }

    private function hydrate(object $row): StoredSubmission
    {
        $fields = json_decode((string) $row->fields_json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($fields)) {
            throw new RuntimeException('Stored submission fields are malformed.');
        }
        $normalized = [];
        foreach ($fields as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new RuntimeException('Stored submission fields are malformed.');
            }
            $normalized[$name] = $value;
        }
        return new StoredSubmission(
            (int) $row->id,
            (string) $row->form_id,
            $normalized,
            (string) $row->status,
            (string) $row->created_at
        );
    }
}

<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use RuntimeException;

final class JsonlSubmissionStore implements SubmissionStore
{
    public function __construct(private string $path)
    {
    }

    public function save(Submission $submission): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create submission storage directory.');
        }

        $line = json_encode($submission->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to persist submission.');
        }
    }
}

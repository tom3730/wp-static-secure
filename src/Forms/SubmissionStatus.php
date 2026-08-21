<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

final class SubmissionStatus
{
    public const NEW = 'new';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';
    public const SPAM = 'spam';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NEW, self::IN_PROGRESS, self::DONE, self::SPAM];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}

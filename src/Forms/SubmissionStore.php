<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

interface SubmissionStore
{
    public function save(Submission $submission): void;
}

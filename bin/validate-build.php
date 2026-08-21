#!/usr/bin/env php
<?php

declare(strict_types=1);

use WPStaticSecure\Validation\BuildValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php bin/validate-build.php <output-dir> <authoring-origin> <public-origin>\n");
    exit(2);
}

try {
    $report = (new BuildValidator($argv[1], $argv[2], $argv[3]))->validate();
} catch (Throwable $e) {
    fwrite(STDERR, 'Validation could not run: ' . $e->getMessage() . "\n");
    exit(2);
}

foreach ($report->issues() as $issue) {
    $reference = isset($issue['reference']) ? ' [' . $issue['reference'] . ']' : '';
    printf("%s %s %s%s: %s\n", strtoupper($issue['severity']), $issue['type'], $issue['file'], $reference, $issue['message']);
}

printf("Validation %s: %d error(s), %d warning(s).\n", $report->isSuccessful() ? 'PASSED' : 'FAILED', $report->errorCount(), $report->warningCount());
exit($report->isSuccessful() ? 0 : 1);

<?php

declare(strict_types=1);

$package = $argv[1] ?? null;
if (!is_string($package) || $package === '' || !is_file($package)) {
    fwrite(STDERR, "Usage: php scripts/verify-package.php /path/to/wp-static-secure.zip\n");
    exit(2);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required to verify the plugin package.\n");
    exit(2);
}

$zip = new ZipArchive();
if ($zip->open($package) !== true) {
    fwrite(STDERR, "Unable to open plugin package.\n");
    exit(1);
}

$entries = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if (is_string($name)) {
        $entries[] = $name;
    }
}
$zip->close();

sort($entries, SORT_STRING);
$entrySet = array_fill_keys($entries, true);

$required = [
    'wp-static-secure/wp-static-secure.php',
    'wp-static-secure/composer.json',
    'wp-static-secure/src/Plugin.php',
    'wp-static-secure/vendor/autoload.php',
];
foreach ($required as $entry) {
    if (!isset($entrySet[$entry])) {
        fwrite(STDERR, 'Required package entry is missing: ' . $entry . PHP_EOL);
        exit(1);
    }
}

$forbiddenPrefixes = [
    'wp-static-secure/.git',
    'wp-static-secure/.github/',
    'wp-static-secure/tests/',
    'wp-static-secure/scripts/',
    'wp-static-secure/build/',
    'wp-static-secure/vendor/phpunit/',
];
$forbiddenExact = [
    'wp-static-secure/AGENTS.md',
    'wp-static-secure/ARCHITECTURE.md',
    'wp-static-secure/CONTRIBUTING.md',
    'wp-static-secure/DEVELOPMENT.md',
    'wp-static-secure/FORM_SUBMISSIONS.md',
    'wp-static-secure/SECURITY.md',
    'wp-static-secure/phpunit.xml.dist',
    'wp-static-secure/composer.lock',
];

foreach ($entries as $entry) {
    if (!str_starts_with($entry, 'wp-static-secure/')) {
        fwrite(STDERR, 'Package entry escapes the plugin root: ' . $entry . PHP_EOL);
        exit(1);
    }
    if (in_array($entry, $forbiddenExact, true)) {
        fwrite(STDERR, 'Development-only package entry found: ' . $entry . PHP_EOL);
        exit(1);
    }
    foreach ($forbiddenPrefixes as $prefix) {
        if (str_starts_with($entry, $prefix)) {
            fwrite(STDERR, 'Development-only package entry found: ' . $entry . PHP_EOL);
            exit(1);
        }
    }
}

fwrite(STDOUT, 'Package verification passed with ' . count($entries) . ' files.' . PHP_EOL);

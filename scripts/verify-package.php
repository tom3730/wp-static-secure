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
    'wp-static-secure/LICENSE',
    'wp-static-secure/src/Plugin.php',
    'wp-static-secure/vendor/autoload.php',
];
foreach ($required as $entry) {
    if (!isset($entrySet[$entry])) {
        fwrite(STDERR, 'Required package entry is missing: ' . $entry . PHP_EOL);
        exit(1);
    }
}

$bootstrap = file_get_contents('zip://' . $package . '#wp-static-secure/wp-static-secure.php');
$pluginClass = file_get_contents('zip://' . $package . '#wp-static-secure/src/Plugin.php');
$composerJson = file_get_contents('zip://' . $package . '#wp-static-secure/composer.json');
if (!is_string($bootstrap) || !preg_match('/^ \* Version: ([^\r\n]+)$/m', $bootstrap, $headerVersion)) {
    fwrite(STDERR, "Packaged plugin version header is missing or malformed.\n");
    exit(1);
}
if (!is_string($pluginClass) || !preg_match("/public const VERSION = '([^']+)';/", $pluginClass, $runtimeVersion)) {
    fwrite(STDERR, "Packaged runtime version is missing or malformed.\n");
    exit(1);
}
if ($headerVersion[1] !== $runtimeVersion[1]) {
    fwrite(STDERR, 'Packaged version mismatch: header ' . $headerVersion[1] . ' != runtime ' . $runtimeVersion[1] . PHP_EOL);
    exit(1);
}
if (!is_string($composerJson)) {
    fwrite(STDERR, "Packaged Composer metadata is unreadable.\n");
    exit(1);
}
$composer = json_decode($composerJson, true);
if (!is_array($composer) || ($composer['license'] ?? null) !== 'Apache-2.0') {
    fwrite(STDERR, "Packaged Composer license must be Apache-2.0.\n");
    exit(1);
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

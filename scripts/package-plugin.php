<?php

declare(strict_types=1);

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

const PACKAGE_DIRECTORY = 'wp-static-secure';
const FIXED_ZIP_MTIME = 315532800; // 1980-01-01T00:00:00Z, the ZIP epoch.

$root = dirname(__DIR__);
$output = $argv[1] ?? ($root . '/build/wp-static-secure.zip');
if (!str_starts_with($output, DIRECTORY_SEPARATOR)) {
    $output = $root . DIRECTORY_SEPARATOR . $output;
}

$stagingRoot = sys_get_temp_dir() . '/wp-static-secure-package-' . bin2hex(random_bytes(8));
$pluginRoot = $stagingRoot . DIRECTORY_SEPARATOR . PACKAGE_DIRECTORY;

try {
    mkdirOrFail($pluginRoot);

    foreach (['wp-static-secure.php', 'composer.json'] as $file) {
        copyFile($root . DIRECTORY_SEPARATOR . $file, $pluginRoot . DIRECTORY_SEPARATOR . $file);
    }
    foreach (['src', 'bin'] as $directory) {
        copyTree($root . DIRECTORY_SEPARATOR . $directory, $pluginRoot . DIRECTORY_SEPARATOR . $directory);
    }

    runComposerInstall($pluginRoot);

    // Composer may create a lock file when the source tree does not carry one.
    // It is build metadata, not a runtime requirement, so the package allowlist excludes it.
    $generatedLock = $pluginRoot . DIRECTORY_SEPARATOR . 'composer.lock';
    if (is_file($generatedLock)) {
        unlink($generatedLock);
    }

    if (!is_file($pluginRoot . '/vendor/autoload.php')) {
        throw new RuntimeException('Packaging did not produce vendor/autoload.php.');
    }

    $outputDirectory = dirname($output);
    mkdirOrFail($outputDirectory);
    if (is_file($output) && !unlink($output)) {
        throw new RuntimeException('Unable to replace existing package: ' . $output);
    }

    createDeterministicZip($stagingRoot, $output);
    fwrite(STDOUT, $output . PHP_EOL);
} finally {
    removeTree($stagingRoot);
}

function runComposerInstall(string $workingDirectory): void
{
    $command = [
        'composer',
        'install',
        '--working-dir=' . $workingDirectory,
        '--no-dev',
        '--prefer-dist',
        '--no-interaction',
        '--no-progress',
        '--classmap-authoritative',
    ];

    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Composer.');
    }

    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Composer failed while preparing runtime dependencies.');
    }
}

function createDeterministicZip(string $stagingRoot, string $output): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP zip extension is required to package the plugin.');
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $files[] = $item->getPathname();
        }
    }
    sort($files, SORT_STRING);

    $zip = new ZipArchive();
    if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create package: ' . $output);
    }

    foreach ($files as $file) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($stagingRoot) + 1));
        if (!$zip->addFile($file, $relative)) {
            $zip->close();
            throw new RuntimeException('Unable to add package file: ' . $relative);
        }
        if (method_exists($zip, 'setMtimeName')) {
            $zip->setMtimeName($relative, FIXED_ZIP_MTIME);
        }
    }

    if (!$zip->close()) {
        throw new RuntimeException('Unable to finalize package: ' . $output);
    }
}

function copyTree(string $source, string $destination): void
{
    mkdirOrFail($destination);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            mkdirOrFail($target);
        } elseif ($item->isFile()) {
            copyFile($item->getPathname(), $target);
        }
    }
}

function copyFile(string $source, string $destination): void
{
    if (!is_file($source)) {
        throw new RuntimeException('Required package source is missing: ' . $source);
    }
    mkdirOrFail(dirname($destination));
    if (!copy($source, $destination)) {
        throw new RuntimeException('Unable to copy package source: ' . $source);
    }
}

function mkdirOrFail(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create directory: ' . $directory);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

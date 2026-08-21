<?php

declare(strict_types=1);

$root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$uriPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($root === false || !is_string($uriPath)) {
    http_response_code(400);
    echo "Bad Request\n";
    return;
}

$decodedPath = rawurldecode($uriPath);
if (str_contains($decodedPath, "\0") || str_contains($decodedPath, '\\')) {
    http_response_code(400);
    echo "Bad Request\n";
    return;
}

$segments = [];
foreach (explode('/', $decodedPath) as $segment) {
    if ($segment === '' || $segment === '.') {
        continue;
    }
    if ($segment === '..') {
        http_response_code(404);
        echo "Not Found\n";
        return;
    }
    $segments[] = $segment;
}

$candidate = $root . ($segments === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
if (is_dir($candidate)) {
    $candidate .= DIRECTORY_SEPARATOR . 'index.html';
}

$resolved = realpath($candidate);
if (
    $resolved === false
    || !is_file($resolved)
    || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not Found\n";
    return;
}

$extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
$contentTypes = [
    'html' => 'text/html; charset=UTF-8',
    'htm' => 'text/html; charset=UTF-8',
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain; charset=UTF-8',
    'xml' => 'application/xml; charset=UTF-8',
];

header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($resolved));
readfile($resolved);

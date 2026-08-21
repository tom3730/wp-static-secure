<?php

declare(strict_types=1);

return [
    'https://wp.internal.example/' => [
        'text/html; charset=UTF-8',
        '<!doctype html><html><head><link rel="stylesheet" href="https://wp.internal.example/assets/site.css"><link rel="canonical" href="https://wp.internal.example/"></head><body><nav><a href="https://wp.internal.example/contact/">Contact</a></nav><img src="https://wp.internal.example/assets/logo.svg" alt="Logo"></body></html>',
    ],
    'https://wp.internal.example/contact/' => [
        'text/html; charset=UTF-8',
        '<!doctype html><html><head><link rel="stylesheet" href="https://wp.internal.example/assets/site.css"></head><body><nav><a href="https://wp.internal.example/">Home</a></nav><h1>Contact</h1><form data-wpss-form="contact" action="https://wp.internal.example/wp-admin/admin-post.php" method="post"><input type="hidden" name="action" value="legacy_contact"><input type="email" name="email"><textarea name="message"></textarea><button type="submit">Send</button></form></body></html>',
    ],
    'https://wp.internal.example/assets/site.css' => [
        'text/css',
        '.logo { background-image: url("https://wp.internal.example/assets/logo.svg"); }',
    ],
    'https://wp.internal.example/assets/logo.svg' => [
        'image/svg+xml',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>',
    ],
];

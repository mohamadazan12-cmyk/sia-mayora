<?php
/**
 * PHP Built-in Server Router Script
 * Usage: php -S localhost:8000 router.php
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$publicFile = __DIR__ . '/public' . $uri;

if ($uri !== '/' && $uri !== '/index.php' && file_exists($publicFile) && !is_dir($publicFile)) {
    return false;
}

chdir(__DIR__ . '/public');
require __DIR__ . '/public/index.php';

<?php
/**
 * Root Entry Point & Router for PHP Built-in Server
 *
 * Allows running `php -S localhost:8000` directly from the project root directory
 * as well as standard hosting configurations.
 * Seamlessly routes requests to public/index.php and serves static assets.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Serve static assets from public/ if requested directly
if ($uri !== '/' && $uri !== '/index.php') {
    $staticFile = __DIR__ . '/public' . $uri;
    if (file_exists($staticFile) && !is_dir($staticFile)) {
        $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
        $mimes = [
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'json'  => 'application/json',
        ];
        if (isset($mimes[$ext])) {
            header('Content-Type: ' . $mimes[$ext]);
        }
        readfile($staticFile);
        exit;
    }
}

// Forward to public/index.php
chdir(__DIR__ . '/public');
require __DIR__ . '/public/index.php';

<?php
// Root router for WAMP Server and PHP Built-in Server
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Check if request is for API
if (strpos($uri, '/api') !== false) {
    require_once __DIR__ . '/api/index.php';
    exit;
}

// Check if request is for Admin Portal
if (preg_match('#^/(?:accounting/)?admin(?:/.*)?$#', $uri)) {
    if (file_exists(__DIR__ . '/admin/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile(__DIR__ . '/admin/index.html');
        exit;
    }
}

// Otherwise serve public/index.html (Client Accounting Portal)
if (file_exists(__DIR__ . '/public/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile(__DIR__ . '/public/index.html');
    exit;
}

require_once __DIR__ . '/public/index.php';

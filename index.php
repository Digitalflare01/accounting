<?php
// Root router for ApexLedger (Supports Apache, WAMP, Hostinger, cPanel, and PHP Built-in Server)
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Normalize URI (strip subdirectories if hosted in a subfolder like /accounting)
$normalizedUri = preg_replace('#^/accounting#', '', $uri);

// Route: API requests
if (preg_match('#^/api(?:/.*)?$#', $normalizedUri) || strpos($uri, '/api') !== false) {
    require_once __DIR__ . '/api/index.php';
    exit;
}

// Route: Admin Portal
if (preg_match('#^/admin(?:/.*)?$#', $normalizedUri)) {
    if (file_exists(__DIR__ . '/admin/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile(__DIR__ . '/admin/index.html');
        exit;
    }
}

// Route: Client Accounting Portal (Serve public/index.html)
if (file_exists(__DIR__ . '/public/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile(__DIR__ . '/public/index.html');
    exit;
}

require_once __DIR__ . '/public/index.php';

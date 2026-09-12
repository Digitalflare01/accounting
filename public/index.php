<?php
// Route requests to static frontend or API
$requestUri = $_SERVER['REQUEST_URI'];

if (strpos($requestUri, '/api/') !== false || strpos($requestUri, '/api') !== false) {
    require_once __DIR__ . '/../api/index.php';
    exit;
}

// Serve public/index.html
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
readfile(__DIR__ . '/index.html');

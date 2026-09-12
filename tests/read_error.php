<?php
$html = file_get_contents(__DIR__ . '/chrome_dom_error.html');
if (strpos($html, 'fatal-error-overlay') !== false) {
    preg_match('/<div id="fatal-error-overlay"[^>]*>([\s\S]*?)<\/div>/', $html, $m);
    echo "ERROR CAPTURED:\n" . strip_tags($m[0]) . "\n";
} else {
    echo "No fatal error overlay found. Root div:\n";
    preg_match('/<div id="root">([\s\S]*?)<\/div>/', $html, $m);
    echo substr($m[0] ?? 'No root div', 0, 1000) . "\n";
}

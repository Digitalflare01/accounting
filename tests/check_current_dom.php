<?php
$cmd = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=5000 --dump-dom "http://localhost/accounting/public/index.html"';
$html = shell_exec($cmd);
file_put_contents(__DIR__ . '/current_chrome_dom.html', $html);

if (strpos($html, 'id="fatal-error-overlay"') !== false) {
    preg_match('/<div id="fatal-error-overlay"[^>]*>([\s\S]*?)<\/div>/', $html, $m);
    echo "FATAL ERROR DETECTED IN DOM:\n" . strip_tags($m[0] ?? '') . "\n";
} else {
    echo "NO fatal error overlay in DOM.\n";
    if (preg_match('/<div id="root">([\s\S]*?)<\/div>/', $html, $m)) {
        echo "Root inner HTML length: " . strlen($m[1]) . "\n";
        if (strlen($m[1]) === 0) {
            echo "WARNING: Root div is completely empty! React did not render!\n";
        } else {
            echo "SUCCESS: React rendered! First 300 chars of root:\n" . substr(strip_tags($m[1]), 0, 300) . "\n";
        }
    } else {
        echo "No root div found!\n";
    }
}

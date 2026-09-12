<?php
$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=5000 --dump-dom "http://localhost/accounting/admin/index.html"';
$out = shell_exec($chrome);
file_put_contents(__DIR__ . '/admin_chrome_dom.html', $out);

echo "Admin HTML length: " . strlen($out) . "\n";
if (strpos($out, 'fatal-error-overlay') !== false || strpos($out, 'SyntaxError') !== false || strpos($out, 'Uncaught') !== false) {
    echo "Potential error in Admin!\n";
} else {
    echo "Admin rendered cleanly!\n";
}

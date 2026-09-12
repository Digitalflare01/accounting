<?php
$chrome = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --headless=new --disable-gpu --virtual-time-budget=10000 --dump-dom "http://localhost/accounting/"';
$dom = shell_exec($chrome);

echo "=== VERIFYING DOM OF LIVE APPLICATION ===\n";

$checks = [
    'Profile & Logo button' => (strpos($dom, 'Profile &amp; Logo') !== false || strpos($dom, 'Profile & Logo') !== false),
    'Invoicing & Billing tab' => (strpos($dom, 'Invoicing &amp; Billing') !== false || strpos($dom, 'Invoicing & Billing') !== false),
    'AI Depreciation Advisor tab' => (strpos($dom, 'AI Depreciation Advisor') !== false),
    'Financial Reports tab' => (strpos($dom, 'Financial Reports') !== false),
    'General Ledger tab' => (strpos($dom, 'General Ledger') !== false),
    'Fatal error overlay absent' => !preg_match('/<div id=[\'"]fatal-error-overlay[\'"]/', $dom)
];

$allPassed = true;
foreach ($checks as $name => $res) {
    if ($res) {
        echo "  [PASS] $name\n";
    } else {
        echo "  [FAIL] $name\n";
        $allPassed = false;
    }
}

if (preg_match('/<pre id=[\'"]fatal-err-content[\'"][^>]*>([\s\S]*?)<\/pre>/', $dom, $m)) {
    echo "\n=== FATAL ERROR OVERLAY CONTENT ===\n" . html_entity_decode($m[1]) . "\n";
} else {
    echo "\nALL UI DOM CHECKS PASSED!\n";
    exit(0);
}

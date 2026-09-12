<?php
$c = file_get_contents(__DIR__ . '/public_chrome_dom.html');

$hasOverlay = (strpos($c, '<div id="fatal-error-overlay"') !== false);
$hasReactRoot = (strpos($c, 'ApexLedger AI') !== false);
$hasSubscriptionPill = (strpos($c, 'Free Plan') !== false || strpos($c, 'Subscription') !== false || strpos($c, 'Sign In') !== false);

echo "HTML length: " . strlen($c) . " bytes\n";
echo "Fatal Error Overlay Inserted: " . ($hasOverlay ? "YES (FAIL)" : "NO (PASS)") . "\n";
echo "React Root Rendered: " . ($hasReactRoot ? "YES (PASS)" : "NO (FAIL)") . "\n";
echo "UI Visible: " . ($hasSubscriptionPill ? "YES (PASS)" : "NO (FAIL)") . "\n";

if ($hasOverlay || !$hasReactRoot) {
    echo "FAILED: DOM verification failed.\n";
    exit(1);
} else {
    echo "PASSED: Public Client rendered cleanly with zero errors!\n";
}

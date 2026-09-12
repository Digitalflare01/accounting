<?php
$c = file_get_contents(__DIR__ . '/public_chrome_dom.html');
echo "fatal-error-overlay: " . (strpos($c, 'id="fatal-error-overlay"') !== false ? 'YES' : 'NO') . "\n";
echo "SyntaxError: " . (strpos($c, 'SyntaxError') !== false ? 'YES' : 'NO') . "\n";
echo "Uncaught: " . (strpos($c, 'Uncaught') !== false ? 'YES' : 'NO') . "\n";

if (strpos($c, 'id="fatal-error-overlay"') !== false) {
    preg_match('/<div id="fatal-error-overlay".*?<\/div>/s', $c, $m);
    echo "Overlay:\n" . ($m[0] ?? 'not matched') . "\n";
}

// Search for 'Uncaught' occurrences
$offset = 0;
while (($pos = strpos($c, 'Uncaught', $offset)) !== false) {
    echo "Uncaught snippet: " . substr($c, max(0, $pos - 40), 120) . "\n";
    $offset = $pos + 8;
}

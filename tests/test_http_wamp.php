<?php
$ch = curl_init('http://localhost/accounting/public/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "Public App HTTP {$code} | Length: " . strlen((string)$res) . "\n";
assert($code === 200, "Must be 200");
assert(strpos($res, 'Financial Reports & Accounting Principles') !== false, "HTML must contain Reports tab");
assert(strpos($res, 'Trial Balance (Dr = Cr)') !== false, "HTML must contain Trial Balance");
assert(strpos($res, 'Cash Flow (Ind AS 7)') !== false, "HTML must contain Cash Flow");
assert(strpos($res, 'Principles & Golden Rules') !== false, "HTML must contain Principles");
echo "✓ Public Web Client successfully serving over Apache WAMP!\n\n";

$ch2 = curl_init('http://localhost/accounting/admin/');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$res2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
echo "Admin App HTTP {$code2} | Length: " . strlen((string)$res2) . "\n";
assert($code2 === 200, "Must be 200");
assert(strpos($res2, 'Google Database Accounting Principles & Golden Rules') !== false, "Admin HTML must contain Principles engine");
echo "✓ Admin Console successfully serving over Apache WAMP!\n";

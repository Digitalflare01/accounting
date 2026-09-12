<?php
declare(strict_types=1);

echo "=== Testing Admin URL Routing and Functionality ===\n\n";

function testUrl($url, $followLocation = false) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followLocation);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['code' => $httpCode, 'effectiveUrl' => $effectiveUrl, 'response' => $response];
}

// 1. Direct http://localhost/admin/ (the URL in user's screenshot)
echo "1. Testing http://localhost/admin/ (without follow location)...\n";
$res1 = testUrl('http://localhost/admin/', false);
echo "   Status Code: {$res1['code']}\n";
assert($res1['code'] === 302, "Expected 302 redirect for /admin/");
assert(strpos($res1['response'], 'Location: http://localhost/accounting/admin/') !== false, "Expected redirect to /accounting/admin/");
echo "   ✓ Redirects properly to /accounting/admin/\n\n";

// 2. Following redirect for http://localhost/admin/
echo "2. Testing http://localhost/admin/ with CURLOPT_FOLLOWLOCATION...\n";
$res2 = testUrl('http://localhost/admin/', true);
echo "   Final Status Code: {$res2['code']}\n";
echo "   Effective URL: {$res2['effectiveUrl']}\n";
assert($res2['code'] === 200, "Expected 200 OK after following redirect");
assert(strpos($res2['response'], 'APEX ADMIN') !== false, "Expected Admin HTML content");
echo "   ✓ Successfully serves Admin Portal HTML (200 OK)\n\n";

// 3. Testing http://localhost/admin (without trailing slash)
echo "3. Testing http://localhost/admin (no trailing slash)...\n";
$res3 = testUrl('http://localhost/admin', true);
echo "   Final Status Code: {$res3['code']}\n";
assert($res3['code'] === 200, "Expected 200 OK for /admin");
echo "   ✓ Successfully resolves to Admin Portal HTML\n\n";

// 4. Testing http://localhost/accounting/admin/ directly
echo "4. Testing http://localhost/accounting/admin/ directly...\n";
$res4 = testUrl('http://localhost/accounting/admin/', false);
echo "   Status Code: {$res4['code']}\n";
assert($res4['code'] === 200, "Expected 200 OK for direct /accounting/admin/");
echo "   ✓ Direct access works with 200 OK\n\n";

// 5. Testing Admin Login and Dashboard API
echo "5. Testing Admin API Authentication...\n";
$ch = curl_init('http://localhost/accounting/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'admin@accounting.local',
    'password' => 'Password123!'
]));
$loginRaw = curl_exec($ch);
$loginJson = json_decode($loginRaw, true);
curl_close($ch);

assert(!empty($loginJson['success']), "Admin login failed: " . $loginRaw);
$token = $loginJson['token'];
echo "   ✓ Admin logged in successfully! Token received.\n\n";

// 6. Testing Admin Dashboard Endpoint with Token
echo "6. Testing Admin Dashboard API (/api/admin/dashboard)...\n";
$ch = curl_init('http://localhost/accounting/api/admin/dashboard');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
$dashRaw = curl_exec($ch);
$dashJson = json_decode($dashRaw, true);
curl_close($ch);

assert(!empty($dashJson['success']), "Dashboard fetch failed: " . $dashRaw);
echo "   ✓ Admin Dashboard API verified! Total registered users: " . ($dashJson['data']['users']['total'] ?? 'N/A') . "\n\n";

echo "=== All Admin Routing & API Tests PASSED! ===\n";

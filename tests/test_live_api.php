<?php
// Test end-to-end HTTP API over real HTTP socket
$baseUrl = 'http://127.0.0.1:8080';

// 1. Admin login over HTTP
$ch = curl_init("{$baseUrl}/api/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'admin@accounting.local',
    'password' => 'Password123!'
]));
$loginRes = curl_exec($ch);
$loginJson = json_decode($loginRes, true);
curl_close($ch);

if (empty($loginJson['token'])) {
    echo "[FAIL] Live Admin Login failed: $loginRes\n";
    exit(1);
}
echo "[PASS] Live Admin Login over HTTP: Token received (Role: {$loginJson['user']['role']})\n";
$token = $loginJson['token'];

// 2. Fetch Dashboard over HTTP with Bearer token
$ch = curl_init("{$baseUrl}/api/admin/dashboard");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$token}",
    "Content-Type: application/json"
]);
$dashRes = curl_exec($ch);
$dashJson = json_decode($dashRes, true);
curl_close($ch);

if (empty($dashJson['success'])) {
    echo "[FAIL] Live Admin Dashboard failed: $dashRes\n";
    exit(1);
}
echo "[PASS] Live Admin Dashboard over HTTP: MRR = ₹{$dashJson['data']['revenue']['mrr']}, Users = {$dashJson['data']['users']['total']}\n";

// 3. Fetch Coupons over HTTP
$ch = curl_init("{$baseUrl}/api/admin/coupons");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$couponsRes = curl_exec($ch);
$couponsJson = json_decode($couponsRes, true);
curl_close($ch);

if (empty($couponsJson['success'])) {
    echo "[FAIL] Live Coupons list failed: $couponsRes\n";
    exit(1);
}
echo "[PASS] Live Coupons over HTTP: " . count($couponsJson['data']['coupons']) . " coupons available\n";

// 4. Check Root /admin/ route serving HTML
$ch = curl_init("{$baseUrl}/admin/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$htmlRes = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && strpos($htmlRes, 'APEX ADMIN') !== false && strpos($htmlRes, 'tab-integrations') !== false) {
    echo "[PASS] Live Admin Console HTML served with code 200 OK (Integrations UI verified)\n";
} else {
    echo "[FAIL] Admin HTML route returned code $httpCode or missing tab-integrations\n";
    exit(1);
}

// 5. GET /api/admin/integrations over HTTP
$ch = curl_init("{$baseUrl}/api/admin/integrations");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$intRes = curl_exec($ch);
$intJson = json_decode($intRes, true);
curl_close($ch);

if (!empty($intJson['success']) && isset($intJson['integrations']['mail']) && isset($intJson['integrations']['whatsapp']) && isset($intJson['integrations']['gemini'])) {
    echo "[PASS] Live GET /api/admin/integrations: Mail ({$intJson['integrations']['mail']['smtp_host']}), WhatsApp ({$intJson['integrations']['whatsapp']['provider']}), Gemini ({$intJson['integrations']['gemini']['model']})\n";
} else {
    echo "[FAIL] Live GET /api/admin/integrations failed: $intRes\n";
    exit(1);
}

// 6. POST /api/admin/integrations/test-mail over HTTP
$ch = curl_init("{$baseUrl}/api/admin/integrations/test-mail");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['recipient_email' => 'admin@accounting.local']));
$mailRes = curl_exec($ch);
$mailJson = json_decode($mailRes, true);
curl_close($ch);

if (!empty($mailJson['success']) && !empty($mailJson['diagnostics']['sample_otp'])) {
    echo "[PASS] Live POST /api/admin/integrations/test-mail: OTP [{$mailJson['diagnostics']['sample_otp']}], Status: {$mailJson['diagnostics']['delivery_status']}\n";
} else {
    echo "[FAIL] Live test-mail failed: $mailRes\n";
    exit(1);
}

// 7. POST /api/admin/integrations/test-whatsapp over HTTP
$ch = curl_init("{$baseUrl}/api/admin/integrations/test-whatsapp");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['phone_number' => '+919876543210']));
$waRes = curl_exec($ch);
$waJson = json_decode($waRes, true);
curl_close($ch);

if (!empty($waJson['success']) && !empty($waJson['diagnostics']['otp_generated'])) {
    echo "[PASS] Live POST /api/admin/integrations/test-whatsapp: OTP [{$waJson['diagnostics']['otp_generated']}], Status: {$waJson['diagnostics']['status']}\n";
} else {
    echo "[FAIL] Live test-whatsapp failed: $waRes\n";
    exit(1);
}

// 8. POST /api/admin/integrations/test-gemini over HTTP
$ch = curl_init("{$baseUrl}/api/admin/integrations/test-gemini");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['prompt' => 'Purchased computer monitors for 45000 with 18% GST']));
$gemRes = curl_exec($ch);
$gemJson = json_decode($gemRes, true);
curl_close($ch);

if (!empty($gemJson['success']) && isset($gemJson['benchmark']['latency_ms'])) {
    echo "[PASS] Live POST /api/admin/integrations/test-gemini: Latency {$gemJson['benchmark']['latency_ms']} ms, Status: {$gemJson['benchmark']['status']}\n";
} else {
    echo "[FAIL] Live test-gemini failed: $gemRes\n";
    exit(1);
}

echo "\nALL LIVE HTTP TESTS PASSED CLEANLY!\n";

